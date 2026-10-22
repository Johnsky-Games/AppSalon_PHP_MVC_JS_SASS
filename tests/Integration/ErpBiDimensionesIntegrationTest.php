<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;

/**
 * Modelo de lectura BI, dimensiones (062): una sola dimensión de fecha continua que cubre todos los hechos, dimensiones de producto, servicio y
 * profesional sin datos personales, y una dimensión de colaborador laboral (mismo grupo de acceso que bi_rh_*). Unir hechos con dimensiones no
 * duplica ni pierde filas (la relación es de muchos a uno).
 */
final class ErpBiDimensionesIntegrationTest extends TestCase
{
    private const COMERCIALES=['bi_dim_fecha','bi_dim_producto','bi_dim_servicio','bi_dim_profesional'];
    private const LABORALES=['bi_rh_dim_colaborador'];
    private \mysqli $db;private int $cliente;private int $profA;private int $profB;private int $srv;private int $colA;private int $colB;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('bidim');ActiveRecord::setDB($this->db);$this->cliente=R5CajaFixture::usuario($this->db);
        $this->srv=$this->ins("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Corte de ensayo',19.99,40)");
        $this->ins("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Servicio fuera del catálogo de venta',10.00,30)");
        $this->colA=$this->colaborador('Ana','Privada','profesional','activo','2024-03-01');
        $this->colB=$this->colaborador('Beto','Reservado','recepcion','baja','2023-01-10');
        $this->db->query("UPDATE colaboradores SET baja_en='2025-06-30 18:00:00' WHERE id={$this->colB}");
        $this->profA=$this->ins("INSERT INTO profesionales(nombre,activo,colaborador_id) VALUES('Profesional Privada A',1,{$this->colA})");
        $this->profB=$this->ins("INSERT INTO profesionales(nombre,activo) VALUES('Profesional sin ficha',0)");
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function n(string $sql):int {return (int)($this->db->query($sql)->fetch_row()[0]??0);}
    private function ins(string $sql):int {self::assertTrue($this->db->query($sql)!==false,$this->db->error);return (int)$this->db->insert_id;}
    private function colaborador(string $n,string $a,string $rol,string $estado,string $ingreso):int
    {
        $u=R5CajaFixture::usuario($this->db,'profesional');
        $this->ins("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,telefono,notas_rh,rol,estado,fecha_ingreso,aceptado_en) SELECT id,email,'$n','$a','0991112233','Nota laboral privada','$rol','$estado','$ingreso',NOW() FROM usuarios WHERE id=$u");
        return (int)$this->db->insert_id;
    }
    private function cita(string $fecha,?int $prof):int
    {
        $p=$prof??'NULL';$id=$this->ins("INSERT INTO citas(fecha,hora,usuarioId,profesionalId,estado,duracion_total_minutos) VALUES('$fecha','10:00:00',{$this->cliente},$p,'completada',40)");
        $this->ins("INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES($id,{$this->srv},'Corte histórico',19.99,40)");return $id;
    }
    private function pago(string $ref,string $pagadoAt):int
    {
        return $this->ins("INSERT INTO pagos(cita_id,cita_referencia_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key,pagado_at,estado_verificacion) VALUES(NULL,0,'$ref','manual',12.00,'USD','completado','efectivo','$ref',$pagadoAt,'confirmado')");
    }

    public function testLaDimensionDeFechaEsContinuaCubreTodosLosHechosYSusAtributosSonCorrectos():void
    {
        $this->cita('2026-03-15',$this->profA);$this->cita('2026-10-05',$this->profA);$this->cita('2027-01-01',$this->profA);
        $this->ins("INSERT INTO erp_comision_liquidaciones(colaborador_id,monto_centavos,via,creado_por,creado_en) VALUES({$this->colA},500,'directa',1,'2026-02-02 09:00:00')");
        $min=$this->db->query("SELECT MIN(fecha) a,MAX(fecha) b,COUNT(*) n,COUNT(DISTINCT fecha) d FROM bi_dim_fecha")->fetch_assoc();
        self::assertSame('2026-02-02',$min['a'],'arranca en la fecha más antigua de cualquier vista de hechos (aquí una liquidación)');
        self::assertGreaterThanOrEqual('2027-01-01',$min['b'],'llega al hecho más reciente (una cita futura)');
        self::assertSame([$min['n'],$min['n']],[$min['d'],(string)($this->n("SELECT DATEDIFF('{$min['b']}','{$min['a']}')")+1)],'una fila por día, sin huecos ni repetidos');
        self::assertGreaterThanOrEqual(1,$this->n("SELECT COUNT(*) FROM bi_dim_fecha WHERE fecha=DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR)"),'el rango incluye hoy (hora de Ecuador)');
        // Todo hecho tiene su día en la dimensión: ninguna relación queda sin fila.
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM bi_atenciones a LEFT JOIN bi_dim_fecha f ON f.fecha=a.fecha_programada WHERE f.fecha IS NULL"));
        $f=fn(string $d)=>$this->db->query("SELECT * FROM bi_dim_fecha WHERE fecha='$d'")->fetch_assoc();
        $lunes=$f('2026-10-05');
        self::assertSame(['2026','4','10','202610','octubre','5','1','lunes','0','41'],[$lunes['anio'],$lunes['trimestre'],$lunes['mes'],$lunes['anio_mes'],$lunes['nombre_mes'],$lunes['dia_del_mes'],$lunes['dia_semana'],$lunes['nombre_dia'],$lunes['es_fin_de_semana'],$lunes['semana_iso']]);
        self::assertSame(['sábado','1'],[$f('2026-10-10')['nombre_dia'],$f('2026-10-10')['es_fin_de_semana']]);
        self::assertSame(['domingo','1','7'],[$f('2026-10-11')['nombre_dia'],$f('2026-10-11')['es_fin_de_semana'],$f('2026-10-11')['dia_semana']]);
        self::assertSame('0',$f('2026-10-12')['es_fin_de_semana']);
        self::assertSame(['miércoles','marzo','1'],[$f('2026-03-18')['nombre_dia'],$f('2026-03-18')['nombre_mes'],$f('2026-03-18')['trimestre']]);
        // Año ISO: el 1 de enero de 2027 pertenece a la semana 53 de 2026.
        self::assertSame(['2027','2026','53'],[$f('2027-01-01')['anio'],$f('2027-01-01')['anio_iso'],$f('2027-01-01')['semana_iso']]);
        // Un hecho unido a la dimensión no se duplica ni se pierde.
        self::assertSame($this->n("SELECT COUNT(*) FROM bi_atenciones"),$this->n("SELECT COUNT(*) FROM bi_atenciones a JOIN bi_dim_fecha f ON f.fecha=a.fecha_programada"));
        self::assertSame($this->n("SELECT SUM(minutos_reservados) FROM bi_atenciones"),$this->n("SELECT SUM(a.minutos_reservados) FROM bi_atenciones a JOIN bi_dim_fecha f ON f.fecha=a.fecha_programada"));
    }

    public function testCadaFechaDeCadaVistaDeHechosTieneSuDiaEnLaDimension():void
    {
        // Hechos con fechas fuera del rango de las citas: una ausencia futura y una liquidación anterior a todo.
        $this->cita('2026-10-05',$this->profA);
        $this->ins("INSERT INTO erp_ausencias(colaborador_id,desde,hasta,tipo,motivo,estado,solicitante_id,creado_en) VALUES({$this->colA},'2028-02-27','2028-03-02','vacaciones','Futuras','aprobada',1,NOW())");
        $this->ins("INSERT INTO erp_comision_liquidaciones(colaborador_id,monto_centavos,via,creado_por,creado_en) VALUES({$this->colA},500,'directa',1,'2024-05-01 10:00:00')");
        $this->ins("INSERT INTO erp_asistencia(colaborador_id,entrada,salida,descanso_minutos,motivo,creado_por,creado_en) VALUES({$this->colA},'2025-01-06 22:00:00','2025-01-07 06:00:00',0,'Turno',1,NOW())");
        $revisadas=0;
        foreach($this->db->query("SELECT table_name t,column_name c,data_type d FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name LIKE 'bi\\_%' AND table_name NOT LIKE 'bi\\_dim\\_%' AND table_name<>'bi_rh_dim_colaborador' AND table_name<>'bi_ocupacion_profesional_dia' AND data_type IN ('date','datetime','timestamp')")->fetch_all(MYSQLI_ASSOC) as $col){
            $expr=$col['d']==='date'?"x.`{$col['c']}`":"DATE(x.`{$col['c']}`)";
            $huerfanas=$this->n("SELECT COUNT(*) FROM `{$col['t']}` x LEFT JOIN bi_dim_fecha f ON f.fecha=$expr WHERE $expr IS NOT NULL AND f.fecha IS NULL");
            self::assertSame(0,$huerfanas,"{$col['t']}.{$col['c']} tiene fechas sin día en bi_dim_fecha: añadir la columna a los límites de la dimensión");$revisadas++;
        }
        self::assertGreaterThanOrEqual(28,$revisadas,'se revisaron todas las columnas de fecha de las vistas de hechos');
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM bi_dim_fecha WHERE fecha='2028-03-02'"),'la ausencia futura extiende el rango');
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM bi_dim_fecha WHERE fecha='2024-05-01'"),'la liquidación antigua extiende el rango hacia atrás');
    }

    public function testSinHechosLaDimensionDeFechaTieneAlMenosHoy():void
    {
        $this->db->query("DELETE FROM citas");
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM bi_dim_fecha"));
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM bi_dim_fecha WHERE fecha=DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR)"));
    }

    public function testProductoServicioYProfesionalTienenUnaFilaPorEntidadYUnirlosNoDuplicaHechos():void
    {
        $p1=$this->ins("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('SKU-1','Champú','unidad',5000,350)");
        $p2=$this->ins("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,activo) VALUES('SKU-2','Producto sin catálogo','unidad',0,0)");
        $this->ins("INSERT INTO erp_catalogo(producto_id,precio_centavos,impuesto_pb,tipo,activo,actualizado_por,actualizado_en) VALUES($p1,1250,1500,'venta',1,1,NOW())");
        $this->ins("INSERT INTO erp_catalogo(servicio_id,precio_centavos,impuesto_pb,tipo,activo,actualizado_por,actualizado_en) VALUES({$this->srv},1999,0,'venta',1,1,NOW())");
        self::assertSame($this->n("SELECT COUNT(*) FROM inventario_productos"),$this->n("SELECT COUNT(DISTINCT producto_id) FROM bi_dim_producto"),'una fila por producto, también los que no están en el catálogo');
        $a=$this->db->query("SELECT * FROM bi_dim_producto WHERE producto_id=$p1")->fetch_assoc();
        self::assertSame(['SKU-1','Champú','1','1','venta','1250','1500'],[$a['sku'],$a['nombre'],$a['activo'],$a['en_catalogo_de_venta'],$a['tipo_catalogo'],$a['precio_catalogo_centavos'],$a['impuesto_pb']]);
        $b=$this->db->query("SELECT * FROM bi_dim_producto WHERE producto_id=$p2")->fetch_assoc();
        self::assertSame(['0','0','0','0',null,null],[$b['activo'],$b['en_catalogo_de_venta'],$b['es_insumo'],$b['catalogo_activo'],$b['tipo_catalogo'],$b['precio_catalogo_centavos']],'sin catálogo: no se inventa precio');
        // Un artículo de consumo interno no es de venta ni expone precio de venta; uno «ambos» sí.
        $p3=$this->ins("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas) VALUES('SKU-3','Tinte de uso interno','unidad',0)");
        $p4=$this->ins("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas) VALUES('SKU-4','Producto de venta y consumo','unidad',0)");
        $this->ins("INSERT INTO erp_catalogo(producto_id,precio_centavos,impuesto_pb,tipo,activo,actualizado_por,actualizado_en) VALUES($p3,900,0,'consumo',1,1,NOW())");
        $this->ins("INSERT INTO erp_catalogo(producto_id,precio_centavos,impuesto_pb,tipo,activo,actualizado_por,actualizado_en) VALUES($p4,700,0,'ambos',1,1,NOW())");
        $c3=$this->db->query("SELECT * FROM bi_dim_producto WHERE producto_id=$p3")->fetch_assoc();
        self::assertSame(['0','1','consumo',null],[$c3['en_catalogo_de_venta'],$c3['es_insumo'],$c3['tipo_catalogo'],$c3['precio_catalogo_centavos']],'el consumo interno no se vende ni muestra precio de venta');
        $c4=$this->db->query("SELECT * FROM bi_dim_producto WHERE producto_id=$p4")->fetch_assoc();
        self::assertSame(['1','0','700'],[$c4['en_catalogo_de_venta'],$c4['es_insumo'],$c4['precio_catalogo_centavos']]);
        $s=$this->db->query("SELECT * FROM bi_dim_servicio WHERE servicio_id={$this->srv}")->fetch_assoc();
        self::assertSame(['Corte de ensayo','1999','40','1'],[$s['nombre'],$s['precio_actual_centavos'],$s['duracion_minutos'],$s['en_catalogo_de_venta']],'19.99 → 1999 centavos exactos');
        self::assertSame(2,$this->n("SELECT COUNT(*) FROM bi_dim_servicio"));
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM bi_dim_servicio WHERE en_catalogo_de_venta=0"));
        self::assertSame(2,$this->n("SELECT COUNT(*) FROM bi_dim_profesional"),'una fila por profesional de la agenda');
        self::assertSame([1,1],[$this->n("SELECT tiene_ficha_laboral FROM bi_dim_profesional WHERE profesional_id={$this->profA}"),$this->n("SELECT SUM(tiene_ficha_laboral) FROM bi_dim_profesional")]);
        // Hechos: cada cita/servicio de cita se une a una sola fila de dimensión.
        $c=$this->cita('2026-10-05',$this->profA);$this->cita('2026-10-06',$this->profB);$this->cita('2026-10-07',null);
        self::assertSame($this->n("SELECT COUNT(*) FROM bi_atencion_servicios"),$this->n("SELECT COUNT(*) FROM bi_atencion_servicios x JOIN bi_dim_servicio d ON d.servicio_id=x.servicio_id"));
        self::assertSame($this->n("SELECT COUNT(*) FROM bi_atenciones WHERE profesional_id IS NOT NULL"),$this->n("SELECT COUNT(*) FROM bi_atenciones a JOIN bi_dim_profesional d ON d.profesional_id=a.profesional_id"));
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM bi_atenciones WHERE profesional_id IS NULL"),'la cita histórica sin profesional no se une a ninguna fila: se ve como «sin profesional»');
        self::assertGreaterThan(0,$c);
        // El precio del catálogo cambia, lo reservado conserva el suyo.
        $this->db->query("UPDATE servicios SET precio=30.00 WHERE id={$this->srv}");
        self::assertSame(3000,$this->n("SELECT precio_actual_centavos FROM bi_dim_servicio WHERE servicio_id={$this->srv}"));
        self::assertSame(1999,$this->n("SELECT precio_centavos FROM bi_atencion_servicios WHERE cita_id=$c"),'el hecho guarda el precio de cuando se reservó');
    }

    public function testLaDimensionDeColaboradorEsLaboralTieneUnaFilaPorPersonaYNoLlevaDatosPersonales():void
    {
        self::assertSame(2,$this->n("SELECT COUNT(*) FROM bi_rh_dim_colaborador"),'una fila por colaborador, con o sin agenda');
        $a=$this->db->query("SELECT * FROM bi_rh_dim_colaborador WHERE colaborador_id={$this->colA}")->fetch_assoc();
        self::assertSame(['profesional','activo','2024-03-01',null,(string)$this->profA],[$a['rol'],$a['estado'],$a['fecha_ingreso'],$a['fecha_baja'],$a['profesional_id']],'enlace con su agenda');
        $b=$this->db->query("SELECT * FROM bi_rh_dim_colaborador WHERE colaborador_id={$this->colB}")->fetch_assoc();
        self::assertSame(['recepcion','baja','2025-06-30',null],[$b['rol'],$b['estado'],$b['fecha_baja'],$b['profesional_id']]);
        // Une con los hechos laborales sin duplicarlos.
        $this->ins("INSERT INTO erp_comision_liquidaciones(colaborador_id,monto_centavos,via,creado_por,creado_en) VALUES({$this->colA},500,'directa',1,NOW())");
        self::assertSame($this->n("SELECT COUNT(*) FROM bi_rh_comisiones_liquidaciones"),$this->n("SELECT COUNT(*) FROM bi_rh_comisiones_liquidaciones l JOIN bi_rh_dim_colaborador d ON d.colaborador_id=l.colaborador_id"));
    }

    public function testLasDimensionesSonDeSoloLecturaSinDatosPersonalesYElAccesoLaboralSeSeparaDelComercial():void
    {
        $prohibidas=['email','correo','telefono','identificacion','cedula','ruc','direccion','sueldo','salario','base_centavos','motivo','nota','observacion','actor','creado_por','usuario','apellido','costo','stock'];
        foreach(array_merge(self::COMERCIALES,self::LABORALES) as $v){
            self::assertSame(1,$this->n("SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='$v' AND security_type='DEFINER'"));
            $cols=array_column($this->db->query("SELECT column_name c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$v'")->fetch_all(MYSQLI_ASSOC),'c');
            foreach($cols as $c){
                foreach($prohibidas as $p)self::assertStringNotContainsString($p,strtolower($c),"$v.$c no debe exponer datos personales");
                self::assertNotSame('nombre_persona',$c);
            }
            $texto=json_encode($this->db->query("SELECT * FROM `$v`")->fetch_all(MYSQLI_ASSOC));
            foreach(['Ana','Privada','Reservado','Nota laboral','0991112233','Profesional Privada A','Profesional sin ficha','@example.test'] as $s)self::assertStringNotContainsString($s,$texto,"$v no debe contener $s");
        }
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name IN ('bi_dim_profesional') AND column_name IN ('nombre','colaborador_id')"),'la dimensión comercial de profesional no lleva nombre ni el puente al colaborador');
        $bd=BaseDatosAislada::nombre($this->db);
        $crear=function(array $vistas)use($bd){
            $u='bi_d_'.bin2hex(random_bytes(4));$c='Bi'.bin2hex(random_bytes(6));
            $this->db->query("CREATE USER '$u'@'%' IDENTIFIED BY '$c'");foreach($vistas as $v)$this->db->query("GRANT SELECT ON `$bd`.`$v` TO '$u'@'%'");
            mysqli_report(MYSQLI_REPORT_OFF);$bi=new \mysqli(getenv('DB_HOST')?:'appsalon-test-db',$u,$c,$bd,(int)(getenv('DB_PORT')?:3306));self::assertSame(0,$bi->connect_errno);return [$u,$bi];
        };
        [$uCom,$com]=$crear(self::COMERCIALES);[$uLab,$lab]=$crear(self::LABORALES);
        try{
            foreach(self::COMERCIALES as $v)self::assertNotFalse($com->query("SELECT COUNT(*) FROM `$v`"),"$v legible por el usuario comercial: ".$com->error);
            self::assertFalse($com->query("SELECT 1 FROM bi_rh_dim_colaborador LIMIT 1"),'el usuario comercial no lee la dimensión laboral');
            self::assertNotFalse($lab->query("SELECT COUNT(*) FROM bi_rh_dim_colaborador"));
            foreach(self::COMERCIALES as $v)self::assertFalse($lab->query("SELECT 1 FROM `$v` LIMIT 1"),"el usuario laboral (solo su dimensión) no lee $v");
            foreach(['servicios','profesionales','inventario_productos','erp_catalogo','colaboradores','citas'] as $t){self::assertFalse($com->query("SELECT 1 FROM `$t` LIMIT 1"),"no lee la tabla $t");self::assertFalse($lab->query("SELECT 1 FROM `$t` LIMIT 1"),"no lee la tabla $t");}
        }finally{mysqli_report(MYSQLI_REPORT_OFF);foreach([$uCom,$uLab] as $u)$this->db->query("DROP USER IF EXISTS '$u'@'%'");}
        $filas=[$this->n("SELECT COUNT(*) FROM servicios"),$this->n("SELECT COUNT(*) FROM profesionales"),$this->n("SELECT COUNT(*) FROM colaboradores")];
        mysqli_report(MYSQLI_REPORT_OFF);
        foreach(["UPDATE bi_dim_fecha SET anio=1999","DELETE FROM bi_dim_fecha","UPDATE bi_dim_producto SET sku='X'","DELETE FROM bi_dim_producto","UPDATE bi_dim_servicio SET nombre='X'","DELETE FROM bi_dim_servicio","UPDATE bi_dim_profesional SET activo=0","DELETE FROM bi_dim_profesional","UPDATE bi_rh_dim_colaborador SET rol='propietario'","DELETE FROM bi_rh_dim_colaborador"] as $sql)self::assertFalse($this->db->query($sql),"vista no actualizable: $sql");
        mysqli_report(MYSQLI_REPORT_OFF);
        self::assertSame($filas,[$this->n("SELECT COUNT(*) FROM servicios"),$this->n("SELECT COUNT(*) FROM profesionales"),$this->n("SELECT COUNT(*) FROM colaboradores")]);
    }
}
