<?php $titulo='Facturación propia · SRI';include __DIR__.'/_inicio.php'; ?>
<p role="status">Ambiente de pruebas: estos comprobantes no tienen validez tributaria. El piloto admite comprador identificado, servicios atendidos y productos entregados, sin devoluciones ni saldo prepagado aplicado.</p>
<?php if(isset($documento)): $snapshot=json_decode($documento['snapshot_json'],true);$respuesta=json_decode($documento['respuesta_json']??'null',true); ?>
<section class="ui-card">
    <h2>Factura de prueba <?= s($documento['numero']) ?></h2>
    <p>Estado SRI: <strong><?= s($documento['estado']) ?></strong> · Preparada <?= s($documento['creado_en']) ?></p>
    <p>Emisor: <?= s($snapshot['emisor']['razon_social']) ?> · RUC <?= s($snapshot['emisor']['ruc']) ?></p>
    <p>Comprador fiscal: <?= s($snapshot['factura']['comprador']['nombre']) ?> · <?= s($snapshot['factura']['comprador']['identificacion']) ?></p>
    <p>Clave de acceso:</p><input aria-label="Clave de acceso SRI" readonly value="<?= s($documento['clave_acceso']) ?>" style="width:100%;min-width:0">
    <p>Intentos de proceso: <?= (int)$documento['intentos'] ?> · Última actualización <?= s($documento['actualizado_en']) ?></p>
    <?php if($documento['estado']==='recibida'): ?><p>El SRI recibió el XML. Aún falta su autorización; el supervisor consultará la misma clave.</p><?php endif; ?>
    <?php if($documento['estado']==='rechazada'): ?><p>El rechazo requiere revisión. Conserva este documento y su número; este piloto todavía no permite corregirlo ni emitir una sustitución.</p><?php endif; ?>
    <?php if($respuesta): ?>
        <?php if(isset($respuesta['mensaje'])): ?><p><?= s($respuesta['mensaje']) ?></p><?php endif; ?>
        <?php foreach($respuesta['mensajes']??[] as $mensaje): ?><p><?= s($mensaje['identificador'].' · '.$mensaje['mensaje'].' · '.$mensaje['informacionAdicional']) ?></p><?php endforeach; ?>
        <?php if(isset($respuesta['fecha_autorizacion'])): ?><p>Autorización de pruebas: <?= s($respuesta['fecha_autorizacion']) ?></p><?php endif; ?>
    <?php endif; ?>
    <nav class="ops-toolbar"><a href="/gestion/facturacion/xml?id=<?= (int)$documento['id'] ?>">Descargar XML firmado de prueba</a><?php if($documento['estado']==='autorizada'): ?><a href="/gestion/facturacion/xml?id=<?= (int)$documento['id'] ?>&amp;formato=autorizado">Descargar XML con autorización de pruebas</a><a href="/gestion/facturacion/ride?id=<?= (int)$documento['id'] ?>">Ver RIDE / imprimir</a><a href="/gestion/facturacion/pdf?id=<?= (int)$documento['id'] ?>">Descargar RIDE PDF</a><?php endif; ?><a href="/ventas/detalle?id=<?= (int)$documento['venta_id'] ?>">Venta de origen</a><a href="/gestion/facturacion">Directorio fiscal</a></nav>
</section>
<?php if($documento['estado']==='autorizada'): ?>
<section class="ui-card"><h2>Entrega por correo</h2><p>Se enviarán el XML autorizado y el RIDE PDF de este comprobante. Confirma la dirección antes de programar la entrega. El envío no cambia el consentimiento de promociones ni registra otro cobro.</p>
<?php $formulario=['accion'=>'fiscal_correo','ocultos'=>['documento_id'=>$documento['id']],'campos'=>['email'=>['etiqueta'=>'Correo del destinatario','tipo'=>'email','longitud'=>120],'confirmar_destinatario'=>['etiqueta'=>'Confirmo que esta dirección corresponde al destinatario del comprobante','tipo'=>'checkbox']],'boton'=>'Programar entrega del comprobante'];include __DIR__.'/_formulario.php'; ?>
<p>Una entrega por comprobante y dirección. «Aceptado por SMTP» indica aceptación por el servidor de correo; no confirma lectura ni llegada al buzón.</p>
<?php if($entregas??[]): ?><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Destinatario</th><th>Estado</th><th>Intentos</th><th>Último diagnóstico</th><th>Acciones</th></tr></thead><tbody><?php foreach($entregas as $entrega): ?><tr><td><?= s($entrega['destinatario_email']) ?></td><td><?= s(['enviado'=>'Aceptado por SMTP','pendiente'=>'Pendiente','enviando'=>'Enviando','fallido'=>'Fallido','cancelado'=>'Cancelado'][$entrega['estado']]) ?></td><td><?= (int)$entrega['intentos'] ?></td><td><?= s($entrega['ultimo_error']??$entrega['enviado_en']??'') ?></td><td><?php if($entrega['estado']==='fallido'&&$entrega['reintentable']):$formulario=['accion'=>'fiscal_correo_reintentar','ocultos'=>['documento_id'=>$documento['id'],'correo_id'=>$entrega['id']],'campos'=>[],'boton'=>'Reintentar entrega'];include __DIR__.'/_formulario.php';endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php endif; ?>
<?php elseif(isset($ventaId)): ?>
<section class="ui-card"><h2>Preparar factura de prueba · Venta #<?= (int)$ventaId ?></h2><p><?= s($venta['comprador_nombre']) ?> · Total <?= s($dinero($venta['total_centavos'])) ?></p><p>Los conceptos y precios se conservan desde la venta. Esta preparación reserva un número fiscal de pruebas, pero no registra otro cobro ni significa que el SRI lo haya autorizado.</p>
<?php if(!$perfiles): ?>
    <p>La administración debe registrar primero el emisor y certificado del piloto desde la consola privada. No se solicita la contraseña de la firma electrónica en esta pantalla.</p>
<?php elseif($venta['estado']!=='confirmada'): ?>
    <p>Confirma la venta antes de preparar su comprobante.</p>
<?php else:
    $opciones=[];foreach($perfiles as $perfil)$opciones[$perfil['id']]=$perfil['ruc'].' · '.$perfil['establecimiento'].'-'.$perfil['punto_emision'].' · versión '.$perfil['creado_en'];
    $campos=[
        'perfil_id'=>['etiqueta'=>'Emisor y punto de emisión de pruebas','tipo'=>'select','opciones'=>$opciones],
        'comprador[tipo]'=>['etiqueta'=>'Identificación fiscal','tipo'=>'select','opciones'=>['04'=>'RUC','05'=>'Cédula','06'=>'Pasaporte','08'=>'Identificación del exterior']],
        'comprador[identificacion]'=>['etiqueta'=>'Número de identificación fiscal','longitud'=>20],
        'comprador[nombre]'=>['etiqueta'=>'Nombre o razón social fiscal','valor'=>$venta['comprador_nombre'],'longitud'=>300],
        'comprador[direccion]'=>['etiqueta'=>'Dirección del comprador','longitud'=>300,'requerido'=>false],
        'forma_pago'=>['etiqueta'=>'Forma pactada para este comprobante de pruebas','tipo'=>'select','opciones'=>['01'=>'Sin utilización del sistema financiero','20'=>'Otros con utilización del sistema financiero']]
    ];
    $codigos=[500=>'5',800=>'8',1200=>'2',1300=>'10',1400=>'3',1500=>'4'];
    foreach($lineas as $linea){$pb=(int)$linea['impuesto_pb'];$ops=$pb===0?['0'=>'Tarifa 0 %','6'=>'No objeto de impuesto','7'=>'Exento de IVA']:(isset($codigos[$pb])?[$codigos[$pb]=>number_format($pb/100,2).' %']:[]);$campos['categorias_iva['.(int)$linea['id'].']']=['etiqueta'=>'Categoría IVA · '.$linea['nombre'],'tipo'=>'select','opciones'=>$ops];}
    $formulario=['accion'=>'fiscal_factura','venta_id'=>$ventaId,'campos'=>$campos,'boton'=>'Preparar y firmar factura de prueba'];include __DIR__.'/_formulario.php';
endif; ?>
<p><a href="/ventas/detalle?id=<?= (int)$ventaId ?>">Volver a la venta</a></p></section>
<?php else: ?>
<p>Prepara el comprobante desde el detalle de una venta. Aquí puedes seguir su recepción, autorización o diagnóstico por separado.</p>
<form class="formulario ui-filtros ui-card" method="GET">
    <?php $periodoContexto='fiscal';include __DIR__.'/../templates/_periodo_filtro.php'; ?>
    <div class="campo"><label for="fiscal-desde">Desde</label><input id="fiscal-desde" type="date" name="desde" value="<?= s($desde) ?>" required></div>
    <div class="campo"><label for="fiscal-hasta">Hasta</label><input id="fiscal-hasta" type="date" name="hasta" value="<?= s($hasta) ?>" required></div>
    <div class="campo"><label for="fiscal-estado">Estado SRI</label><select id="fiscal-estado" name="estado"><?php foreach([''=>'Todos','pendiente'=>'Pendiente','recibida'=>'Recibida, por autorizar','autorizada'=>'Autorizada en pruebas','rechazada'=>'Rechazada'] as $v=>$t): ?><option value="<?= s($v) ?>"<?= $v===$estado?' selected':'' ?>><?= s($t) ?></option><?php endforeach; ?></select></div>
    <button class="boton">Consultar</button>
</form>
<div class="ops-table-wrap"><table class="ops-table"><caption><?= (int)$total ?> comprobantes de prueba</caption><thead><tr><th>Número</th><th>Comprador de la venta</th><th>Preparado</th><th>Estado SRI</th></tr></thead><tbody>
    <?php foreach($filas as $fila): ?><tr><td><a href="?id=<?= (int)$fila['id'] ?>"><?= s($fila['numero']) ?></a></td><td><?= s($fila['comprador_nombre']) ?></td><td><?= s($fila['creado_en']) ?></td><td><?= s($fila['estado']) ?></td></tr><?php endforeach; ?>
    <?php if(!$filas): ?><tr><td colspan="4">No hay documentos para estos filtros.</td></tr><?php endif; ?>
</tbody></table></div>
<nav class="ops-toolbar" aria-label="Páginas de facturación"><span>Página <?= (int)$pagina ?> de <?= (int)$paginas ?></span><?php foreach(['Anterior'=>$pagina-1,'Siguiente'=>$pagina+1] as $texto=>$n):if($n<1||$n>$paginas)continue; ?><a href="?<?= s(http_build_query(['desde'=>$desde,'hasta'=>$hasta,'estado'=>$estado,'pagina'=>$n])) ?>"><?= s($texto) ?></a><?php endforeach; ?></nav>
<?php endif; ?>
