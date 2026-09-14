-- Klikmissies: keuze "opent in een nieuw venster" — bijwerken van een
-- bestaande database
--
-- Voegt de kolom `nieuw_venster` toe aan de tabel `klikmissies`, die op
-- migratie-klikmissies-2026-09-14.sql volgt. Bepaalt of de Stem-link/-knop
-- van een missie in een nieuw tabblad opent (1, de standaard) of de
-- huidige tab wegstuurt (0).
--
-- Uitvoeren via phpMyAdmin (of de mysql-CLI) tegen je productiedatabase,
-- ná migratie-klikmissies-2026-09-14.sql. Eenmalig: een tweede keer draaien
-- geeft een foutmelding dat de kolom al bestaat, maar wijzigt verder niets.

ALTER TABLE `klikmissies`
  ADD COLUMN `nieuw_venster` tinyint unsigned NOT NULL DEFAULT 1 AFTER `heeft_callback`;

-- --- Controle: staat de kolom er nu op? --------------------------------------

SHOW COLUMNS FROM `klikmissies` LIKE 'nieuw_venster';
