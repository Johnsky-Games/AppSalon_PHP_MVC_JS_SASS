<div class="barra">
    <p>Hola: <?php echo s($nombre . " " . $apellido) ?? ''; ?></p>
    <form action="/logout" method="POST" style="margin: 0;">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="submit" class="boton" value="Cerrar Sesión">
    </form>
</div>

<?php if(isset($_SESSION['admin'])){?>
   <div class='barra-servicios'>
        <a href="/admin" class='boton'>Ver Citas</a>
        <a href="/servicios" class='boton'>Servicios</a>
        <a href="/servicios/crear" class='boton'>Nuevo Servicio</a>
   </div>
<?php } ?>