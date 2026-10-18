<?php $titulo='Documentos del expediente';include __DIR__.'/_inicio.php'; ?>
<p>Expediente <?= s($entidad) ?> #<?= (int)$id ?>. Se conservan las versiones anteriores. Máximo 200 documentos mostrados.</p>
<div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Título</th><th>Archivo</th><th>Vence</th><th>Versión anterior</th><th>Descargar</th></tr></thead><tbody>
<?php foreach($filas as $f): ?><tr><td><?= s($f['titulo']) ?></td><td><?= s($f['nombre_archivo']) ?> (<?= number_format((int)$f['bytes']/1024,1) ?> KB)</td><td><?= s($f['vence_en']??'Sin vencimiento') ?></td><td><?= $f['reemplaza_id']?(int)$f['reemplaza_id']:'Inicial' ?></td><td><a href="/gestion/documentos/descargar?id=<?= (int)$f['id'] ?>">Descargar #<?= (int)$f['id'] ?></a></td></tr><?php endforeach; ?></tbody></table></div>
<?php if($editar): ?><h2>Incorporar documento</h2>
<form class="formulario" method="POST" action="/gestion/accion" enctype="multipart/form-data" data-una-vez>
<?php $clave('documento_subir'); ?><input type="hidden" name="entidad" value="<?= s($entidad) ?>"><input type="hidden" name="entidad_id" value="<?= (int)$id ?>">
<div class="campo"><label for="doc-titulo">Título</label><input id="doc-titulo" name="titulo" maxlength="180" required></div>
<div class="campo"><label for="doc-archivo">Archivo (PDF, JPEG, PNG o texto; hasta 5 MB)</label><input id="doc-archivo" type="file" name="archivo" accept="application/pdf,image/jpeg,image/png,text/plain" required></div>
<div class="campo"><label for="doc-reemplaza">Versión anterior</label><select id="doc-reemplaza" name="reemplaza_id"><option value="">Documento nuevo</option><?php foreach($filas as $f): ?><option value="<?= (int)$f['id'] ?>">#<?= (int)$f['id'] ?> <?= s($f['titulo']) ?></option><?php endforeach; ?></select></div>
<div class="campo"><label for="doc-vence">Vencimiento opcional</label><input id="doc-vence" type="date" name="vence_en"></div>
<?php if($entidad==='colaborador'): ?><div class="campo"><label for="doc-visibilidad">Acceso</label><select id="doc-visibilidad" name="visibilidad"><option value="privado">Solo Recursos Humanos</option><option value="titular">Recursos Humanos y titular</option></select></div><?php endif; ?>
<button class="boton">Guardar documento</button></form><?php endif; ?>
