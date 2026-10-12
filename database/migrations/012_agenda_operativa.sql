CREATE TABLE agenda_eventos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 citaId INT NOT NULL,
 actorId INT NULL,
 tipo VARCHAR(30) NOT NULL,
 antes_json JSON NOT NULL,
 despues_json JSON NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_agenda_eventos_cita (citaId, creado_en),
 CONSTRAINT fk_agenda_evento_cita FOREIGN KEY (citaId) REFERENCES citas(id) ON DELETE CASCADE,
 CONSTRAINT fk_agenda_evento_actor FOREIGN KEY (actorId) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
