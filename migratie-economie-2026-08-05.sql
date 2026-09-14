-- Economiebalans vroege spel (issue #33, #35) — bijwerken van een bestaande database
--
-- install/schema.sql wordt alleen gelezen bij een nieuwe installatie. Deze
-- UPDATE's zetten een al draaiende database op dezelfde waarden. Matcht op
-- `naam` + `type`, niet op `id`: veilig ook als je zelf al iets aan de
-- itemlijst hebt gewijzigd via adm-items.php.
--
-- Uitvoeren via phpMyAdmin (of de mysql-CLI) tegen je productiedatabase.
-- Raakt alleen de tabel `items`; geen enkele speler wordt aangepast.

-- --- Wapens (issue #35: effect is nu een vermenigvuldiger, hoger is beter) ---

UPDATE `items` SET `effect` = 1.20 WHERE `type` = 'att' AND `naam` = '9mm';
UPDATE `items` SET `effect` = 1.60 WHERE `type` = 'att' AND `naam` = 'Uzi';
UPDATE `items` SET `effect` = 2.00 WHERE `type` = 'att' AND `naam` = 'M16';
UPDATE `items` SET `effect` = 2.50 WHERE `type` = 'att' AND `naam` = 'Magnum Semi Auto';
UPDATE `items` SET `effect` = 3.20 WHERE `type` = 'att' AND `naam` = 'Sniper Rifle';
UPDATE `items` SET `effect` = 4.20 WHERE `type` = 'att' AND `naam` = 'Tommy Gun';

-- --- Voertuigen (issue #33: prijzen door 10 gedeeld) --------------------------

UPDATE `items` SET `aprijs` = 25000,  `vprijs` = 20000  WHERE `type` = 'trans' AND `naam` = 'Treinabonnement';
UPDATE `items` SET `aprijs` = 75000,  `vprijs` = 60000  WHERE `type` = 'trans' AND `naam` = 'Taxi';
UPDATE `items` SET `aprijs` = 100000, `vprijs` = 75000  WHERE `type` = 'trans' AND `naam` = 'Limousine';
UPDATE `items` SET `aprijs` = 150000, `vprijs` = 120000 WHERE `type` = 'trans' AND `naam` = 'Privé-Jet';

-- --- Controle: laat de nieuwe waarden zien -----------------------------------

SELECT `type`, `naam`, `aprijs`, `vprijs`, `effect` FROM `items`
 WHERE `type` IN ('att', 'trans') ORDER BY `type`, `aprijs`;
