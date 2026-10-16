<?php 
foreach (($alertas ?? []) as $key => $mensajes){
   foreach ($mensajes as $mensaje){
?>
    <div class="alerta <?php echo s((string)$key); ?>">
        <?php echo s((string)$mensaje); ?>
    </div>
<?php 
   }
}
?>