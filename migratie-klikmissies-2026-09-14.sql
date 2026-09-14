-- Klikmissies (stemlinks met beloning en cooldown) — bijwerken van een
-- bestaande database
--
-- install/schema.sql wordt alleen gelezen bij een nieuwe installatie. Dit
-- bestand voegt de twee nieuwe tabellen toe aan een al draaiende database.
-- CREATE TABLE IF NOT EXISTS is veilig om opnieuw te draaien.
--
-- Uitvoeren via phpMyAdmin (of de mysql-CLI) tegen je productiedatabase.
-- Raakt geen bestaande tabel of speler.

CREATE TABLE IF NOT EXISTS `klikmissies` (
  `id`                 int unsigned NOT NULL AUTO_INCREMENT,
  `naam`               varchar(100) NOT NULL DEFAULT '',
  `omschrijving`       varchar(255) NOT NULL DEFAULT '',
  `url`                varchar(500) NOT NULL DEFAULT '', -- mag `{login}` bevatten
  `heeft_callback`     tinyint unsigned NOT NULL DEFAULT 0,
  `callback_geheim`    varchar(64) NOT NULL DEFAULT '',
  `wachttijd_klik`     int unsigned NOT NULL DEFAULT 20,    -- seconden; alleen zonder callback
  `cooldown_seconden`  int unsigned NOT NULL DEFAULT 86400,
  `beloning_zak`       bigint NOT NULL DEFAULT 0,
  `beloning_bank`      bigint NOT NULL DEFAULT 0,
  `beloning_diamanten` int unsigned NOT NULL DEFAULT 0,
  `actief`             tinyint unsigned NOT NULL DEFAULT 1,
  `volgorde`           int unsigned NOT NULL DEFAULT 0,
  `aangemaakt_op`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `actief` (`actief`, `volgorde`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `klikmissies_log` (
  `id`             int unsigned NOT NULL AUTO_INCREMENT,
  `klikmissie_id`  int unsigned NOT NULL,
  `login`          varchar(16) NOT NULL,
  `tijd`           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `methode`        enum('callback','zelf') NOT NULL,
  `ip`             varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `cooldown` (`klikmissie_id`, `login`, `tijd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Controle: bestaan de tabellen nu? ---------------------------------------

SHOW TABLES LIKE 'klikmissies%';
