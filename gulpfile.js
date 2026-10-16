// Compatibilidad para herramientas que inspeccionan las tareas del proyecto.
// La compilación soportada usa `npm run build` y no necesita ejecutables .bin.
const tasks = require('./scripts/frontend/build.cjs');
exports.css = tasks.css;
exports.javascript = tasks.javascript;
exports.vendor = tasks.vendor;
exports.build = () => tasks.build();
exports.default = exports.build;
