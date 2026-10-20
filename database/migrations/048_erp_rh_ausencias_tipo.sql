-- 048: tipo de ausencia. Permite distinguir vacaciones y licencias de los permisos comunes para calcular saldos y alimentar el finiquito.
-- Las ausencias existentes quedan como «permiso» (no se reclasifica nada retroactivamente).
ALTER TABLE erp_ausencias
 ADD COLUMN tipo ENUM('permiso','vacaciones','licencia_remunerada','licencia_no_remunerada') NOT NULL DEFAULT 'permiso' AFTER hasta;
