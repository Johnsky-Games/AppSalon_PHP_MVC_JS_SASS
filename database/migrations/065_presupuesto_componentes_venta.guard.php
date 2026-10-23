<?php
// Solo redefine una vista de lectura (sin datos propios): revertirla nunca pierde información.
return static function(\mysqli $db):array {return [];};
