-- Black Vendetta - databaseschema
--
-- Wordt automatisch ingelezen door de installer (/install/). Wil je het
-- handmatig doen, importeer dit bestand dan via phpMyAdmin in een lege database.
--
-- Verschillen met de oude sql.sql:
--   * InnoDB in plaats van MyISAM  -> transacties, dus geen geldverdubbeling
--   * utf8mb4                      -> accenten en emoji werken
--   * geen '0000-00-00' datums     -> import faalt niet op moderne MySQL
--   * bigint voor geldbedragen     -> bedragen lopen niet meer over
--   * indexen op zoekkolommen      -> pagina's blijven snel bij veel spelers

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------- spelers

CREATE TABLE IF NOT EXISTS `users` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `login`      varchar(16)  NOT NULL,
  `pass`       varchar(255) NOT NULL,           -- password_hash(), niet MD5
  `email`      varchar(255) NOT NULL DEFAULT '',
  `ip`         varchar(45)  NOT NULL DEFAULT '',-- 45 tekens: ook IPv6 past
  `activated`  tinyint unsigned NOT NULL DEFAULT 0,
  `level`      smallint unsigned NOT NULL DEFAULT 1,  -- 1 speler, 200 mod, 255 admin, 1000 owner
  `status`     enum('levend','dood') NOT NULL DEFAULT 'levend',

  `start`      datetime     NULL DEFAULT NULL,
  `online`     datetime     NULL DEFAULT NULL,

  `stad`       varchar(32)  NOT NULL DEFAULT 'Brussel',
  `geslacht`   enum('Man','Vrouw') NOT NULL DEFAULT 'Man',

  `health`     tinyint unsigned NOT NULL DEFAULT 100,
  `energie`    decimal(4,1) NOT NULL DEFAULT 99.9,
  `xp`         int unsigned NOT NULL DEFAULT 0,
  `se`         decimal(4,1) NOT NULL DEFAULT 0.0,   -- moordervaring in procenten
  `respect`    int unsigned NOT NULL DEFAULT 0,
  `rp`         smallint unsigned NOT NULL DEFAULT 0,

  `zak`        bigint NOT NULL DEFAULT 1000,
  `bank`       bigint NOT NULL DEFAULT 0,
  `banktime`   int unsigned NOT NULL DEFAULT 0,

  -- Afkoeltijden: NULL betekent "nooit gebruikt".
  `ac`         datetime NULL DEFAULT NULL,  -- auto stelen
  `bc`         datetime NULL DEFAULT NULL,  -- bank
  `crime`      datetime NULL DEFAULT NULL,  -- misdaad
  `kc`         datetime NULL DEFAULT NULL,  -- moord
  `pc`         datetime NULL DEFAULT NULL,  -- route 66
  `safe`       datetime NULL DEFAULT NULL,
  `slaap`      datetime NULL DEFAULT NULL,
  `transport`  datetime NULL DEFAULT NULL,
  `drugst`     datetime NULL DEFAULT NULL,
  `drankt`     datetime NULL DEFAULT NULL,

  `famillie`   varchar(20) NOT NULL DEFAULT '',
  `famrang`    tinyint unsigned NOT NULL DEFAULT 0,
  `famcapo`    varchar(25) NOT NULL DEFAULT '',
  -- Tot welke rang het promotiegeld van de familie al is uitbetaald. Zonder
  -- dit veld was er geen manier om te zien of iemand al beloond was, en werd
  -- het bedrag uit fampromotie.php dan ook nooit uitgekeerd.
  `laatste_rang` tinyint unsigned NOT NULL DEFAULT 0,

  `wapon`      tinyint unsigned NOT NULL DEFAULT 0,
  `defence`    tinyint unsigned NOT NULL DEFAULT 0,
  `guard`      tinyint unsigned NOT NULL DEFAULT 0,
  `kogels`     int unsigned NOT NULL DEFAULT 0,
  `trans`      tinyint unsigned NOT NULL DEFAULT 0,
  `drugs`      int unsigned NOT NULL DEFAULT 0,
  `drank`      int unsigned NOT NULL DEFAULT 0,
  `vet`        smallint unsigned NOT NULL DEFAULT 0,
  `sl`         tinyint unsigned NOT NULL DEFAULT 0,
  `bf`         int unsigned NOT NULL DEFAULT 0,
  `bo`         int unsigned NOT NULL DEFAULT 0,

  -- Huisbezit stond hier als één kolom per stad: `Brussel`, `Leuven`, enzovoort.
  -- Daardoor kostte een stad toevoegen een ALTER TABLE, en brak de registratie
  -- zodra iemand in een stad terechtkwam waarvoor de kolom ontbrak. Het staat
  -- nu in de tabel `huizen`.

  `pic`        varchar(255) NOT NULL DEFAULT '',
  `info`       text NULL,
  `testament`  varchar(16) NOT NULL DEFAULT '',
  `huwelijk`   varchar(16) NOT NULL DEFAULT '',
  `lang`       char(2) NOT NULL DEFAULT 'nl',

  -- Premium: één model, per veertien dagen. Zolang `premium_tot` in de
  -- toekomst ligt is het account premium. Opnieuw afsluiten telt de dagen
  -- erbij op, dus verlengen kan zonder eerst te wachten.
  `premium_tot` datetime NULL DEFAULT NULL,
  -- Diamanten: de betaalde munt. Te vinden bij misdaden, of te koop.
  `diamanten`   int unsigned NOT NULL DEFAULT 0,
  -- Hoeveel diamanten dit account ooit gevonden heeft. Alleen voor de sier
  -- en om te zien of de vindkans klopt.
  `diamanten_gevonden` int unsigned NOT NULL DEFAULT 0,
  `ah`         tinyint unsigned NOT NULL DEFAULT 0,
  `dh`         tinyint unsigned NOT NULL DEFAULT 0,
  `gstart`     tinyint unsigned NOT NULL DEFAULT 0,
  -- Rijbewijs. In de oude sql.sql ontbraken deze kolommen volledig, terwijl
  -- rijbewijs.php ze wel gebruikte; dat bestand kon dus nooit werken.
  `rijbewijs`     tinyint unsigned NOT NULL DEFAULT 0,
  `rijvord`       decimal(5,1) NOT NULL DEFAULT 0.0,   -- vordering in procenten
  `lessen`        int unsigned NOT NULL DEFAULT 0,     -- gekochte rijlessen
  `rijbewijstijd` datetime NULL DEFAULT NULL,          -- afkoeltijd tussen lessen

  -- Tellers voor de statistiekenpagina.
  `nrofcrime`  int unsigned NOT NULL DEFAULT 0,
  `nrofcar`    int unsigned NOT NULL DEFAULT 0,
  `nrofroute`  int unsigned NOT NULL DEFAULT 0,
  `nrofoc`     int unsigned NOT NULL DEFAULT 0,
  `nrofrace`   int unsigned NOT NULL DEFAULT 0,
  `nrofkill`   int unsigned NOT NULL DEFAULT 0,
  `play`       int unsigned NOT NULL DEFAULT 0,

  -- Hoe vaak dit account is omgelegd. Blijft staan bij een herstart: het is
  -- de enige teller die een doorstart overleeft, en hij staat in het profiel.
  `gestorven`  int unsigned NOT NULL DEFAULT 0,
  -- Wanneer voor het laatst opnieuw begonnen is. NULL = nooit doodgeweest.
  `herstart`   datetime NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`),
  -- Eén account per e-mailadres. Stond eerder alleen als gewone index, maar
  -- register.php controleert er nu hard op, dus de database bewaakt het mee.
  -- Prefix van 191 tekens: dat past ook op oudere MariaDB-versies met een
  -- indexlimiet van 767 bytes.
  UNIQUE KEY `email_uniek` (`email`(191)),
  KEY `ranglijst` (`status`, `activated`, `xp`),
  KEY `stad_status` (`stad`, `status`),
  KEY `famillie` (`famillie`),
  KEY `online` (`online`),
  -- Eén account per IP-adres; zie register.php. Geen UNIQUE, want een
  -- beheerder kan per adres uitzondering geven via de tabel `multiple`.
  KEY `ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- berichten

CREATE TABLE IF NOT EXISTS `messages` (
  `id`      int unsigned NOT NULL AUTO_INCREMENT,
  `from`    varchar(16) NOT NULL DEFAULT '',
  `to`      varchar(16) NOT NULL DEFAULT '',
  `subject` varchar(80) NOT NULL DEFAULT '',
  `message` text NOT NULL,
  `time`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read`    tinyint unsigned NOT NULL DEFAULT 0,
  `inbox`   tinyint unsigned NOT NULL DEFAULT 0,
  `save`    tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `postvak` (`to`, `read`, `time`),
  KEY `verzonden` (`from`, `time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- steden

CREATE TABLE IF NOT EXISTS `stad` (
  `stad`   varchar(32) NOT NULL,
  `kogels` int unsigned NOT NULL DEFAULT 500,
  `prijs`  int unsigned NOT NULL DEFAULT 1000,
  `drugs`  int unsigned NOT NULL DEFAULT 50,
  `drank`  int unsigned NOT NULL DEFAULT 50,
  `drugsp` int unsigned NOT NULL DEFAULT 2500,
  `drankp` int unsigned NOT NULL DEFAULT 2500,
  -- Wat het kost om hierheen te reizen. Stond eerder hardcoded in
  -- transport.php, waardoor een nieuwe stad onbereikbaar was.
  `transp` int unsigned NOT NULL DEFAULT 2000,
  `grond`  int unsigned NOT NULL DEFAULT 1000,
  PRIMARY KEY (`stad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Huisbezit per speler per stad. Een huis geeft thuisvoordeel bij gevechten
-- in die stad. Eerder was dit een kolom per stad in `users`; zo kost een stad
-- toevoegen geen schemawijziging meer.
CREATE TABLE IF NOT EXISTS `huizen` (
  `login` varchar(16) NOT NULL,
  `stad`  varchar(32) NOT NULL,
  `sinds` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`login`, `stad`),
  KEY `per_stad` (`stad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- families

CREATE TABLE IF NOT EXISTS `famillie` (
  `name`    varchar(20) NOT NULL,
  `info`    text NULL,
  `crusher` int unsigned NOT NULL DEFAULT 0,
  `aantal`  int unsigned NOT NULL DEFAULT 0,
  `stad`    varchar(32) NOT NULL DEFAULT '',
  `pic`     varchar(255) NOT NULL DEFAULT '',
  `bank`    bigint NOT NULL DEFAULT 0,
  `grond`   int unsigned NOT NULL DEFAULT 50,
  -- Uitbetaling per spelersrang bij promotie, ingesteld via fampromotie.php.
  -- Dit zijn bedragen, geen namen.
  `rang2`  bigint NOT NULL DEFAULT 0,
  `rang3`  bigint NOT NULL DEFAULT 0,
  `rang4`  bigint NOT NULL DEFAULT 0,
  `rang5`  bigint NOT NULL DEFAULT 0,
  `rang6`  bigint NOT NULL DEFAULT 0,
  `rang7`  bigint NOT NULL DEFAULT 0,
  `rang8`  bigint NOT NULL DEFAULT 0,
  `rang9`  bigint NOT NULL DEFAULT 0,
  `rang10` bigint NOT NULL DEFAULT 0,
  `rang11` bigint NOT NULL DEFAULT 0,
  `rang12` bigint NOT NULL DEFAULT 0,
  `rang13` bigint NOT NULL DEFAULT 0,
  `rang14` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`name`),
  KEY `stad` (`stad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invite` (
  `login`    varchar(16) NOT NULL,
  `famillie` varchar(20) NOT NULL,
  PRIMARY KEY (`login`, `famillie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- gevangenis

CREATE TABLE IF NOT EXISTS `jail` (
  `id`       int unsigned NOT NULL AUTO_INCREMENT,
  `login`    varchar(16) NOT NULL,
  `boete`    int unsigned NOT NULL DEFAULT 0,
  `time`     datetime NOT NULL,
  `stad`     varchar(32) NOT NULL DEFAULT '',
  `famillie` varchar(20) NOT NULL DEFAULT '',
  `bo`       tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`),
  KEY `vrijlating` (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- garage / auto's

CREATE TABLE IF NOT EXISTS `cars` (
  `id`     int unsigned NOT NULL AUTO_INCREMENT,
  `auto`   varchar(64) NOT NULL,
  `naam`   varchar(64) NOT NULL DEFAULT '',
  `url`    varchar(255) NOT NULL DEFAULT '',
  `waarde` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `waarde` (`waarde`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `garage` (
  `id`     int unsigned NOT NULL AUTO_INCREMENT,
  `login`  varchar(16) NOT NULL,
  `naam`   varchar(64) NOT NULL DEFAULT '',
  `waarde` int unsigned NOT NULL DEFAULT 0,
  `damage` tinyint unsigned NOT NULL DEFAULT 0,
  `stad`   varchar(32) NOT NULL DEFAULT '',
  `safe`   tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `eigenaar` (`login`, `stad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- items en winkel

CREATE TABLE IF NOT EXISTS `items` (
  `id`     int unsigned NOT NULL AUTO_INCREMENT,
  `nr`     tinyint unsigned NOT NULL DEFAULT 0,
  `type`   enum('att','def','trans') NOT NULL,
  `naam`   varchar(32) NOT NULL,
  `aprijs` bigint NOT NULL DEFAULT 0,   -- aankoopprijs
  `vprijs` bigint NOT NULL DEFAULT 0,   -- verkoopprijs
  `effect` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `soort` (`type`, `nr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kogels die op de zwarte markt te koop staan. Wordt de partij niet verkocht
-- voor `time` verstrijkt, dan gaan de kogels terug naar de verkoper.
-- (Deze tabel ontbrak in de oude sql.sql, waardoor de zwarte markt stuk was.)
CREATE TABLE IF NOT EXISTS `kogels` (
  `id`     int unsigned NOT NULL AUTO_INCREMENT,
  `login`  varchar(16) NOT NULL,
  `aantal` int unsigned NOT NULL DEFAULT 0,
  `prijs`  bigint NOT NULL DEFAULT 0,
  `time`   datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `verkoper` (`login`),
  KEY `afloop` (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auto's die op de zwarte markt te koop staan. Zelfde principe: niet verkocht
-- binnen de tijd, dan terug naar de garage van de eigenaar.
-- (Ontbrak eveneens in de oude sql.sql.)
CREATE TABLE IF NOT EXISTS `mgarage` (
  `id`     int unsigned NOT NULL AUTO_INCREMENT,
  `login`  varchar(16) NOT NULL,
  `naam`   varchar(64) NOT NULL DEFAULT '',
  `waarde` int unsigned NOT NULL DEFAULT 0,
  `damage` tinyint unsigned NOT NULL DEFAULT 0,
  `stad`   varchar(32) NOT NULL DEFAULT '',
  `prijs`  bigint NOT NULL DEFAULT 0,
  `time`   datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `verkoper` (`login`),
  KEY `afloop` (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mshop` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `door`       varchar(16) NOT NULL DEFAULT '',
  `type`       varchar(16) NOT NULL DEFAULT '',
  `specs`      varchar(255) NOT NULL DEFAULT '',
  `specs2`     varchar(255) NOT NULL DEFAULT '',
  `specs3`     varchar(255) NOT NULL DEFAULT '',
  `bieder`     varchar(16) NOT NULL DEFAULT '',
  `bod`        bigint NOT NULL DEFAULT 0,
  `aflooptijd` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `afloop` (`aflooptijd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- casino

CREATE TABLE IF NOT EXISTS `casino` (
  `id`     int unsigned NOT NULL AUTO_INCREMENT,
  `spel`   varchar(32) NOT NULL DEFAULT '',
  `owner`  varchar(16) NOT NULL DEFAULT '',
  `stad`   varchar(32) NOT NULL DEFAULT '',
  `winst`  bigint NOT NULL DEFAULT 0,
  `inzet`  bigint NOT NULL DEFAULT 1000,
  `status` tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `spel_stad` (`spel`, `stad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blackjack` (
  `id`        int unsigned NOT NULL AUTO_INCREMENT,
  `login`     varchar(16) NOT NULL,
  `inzet`     bigint NOT NULL DEFAULT 0,
  `kaart`     int NOT NULL DEFAULT 0,
  `kaartpic`  text NULL,
  `aas`       int NOT NULL DEFAULT 0,
  `dealer`    int NOT NULL DEFAULT 0,
  `dealerpic` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kras` (
  `login`  varchar(16) NOT NULL,
  `aantal` tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `loterij` (
  `id`    int unsigned NOT NULL AUTO_INCREMENT,
  `login` varchar(16) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- misdaad

CREATE TABLE IF NOT EXISTS `oc` (
  `id`      int unsigned NOT NULL AUTO_INCREMENT,
  `login`   varchar(16) NOT NULL DEFAULT '',
  `we`      varchar(16) NOT NULL DEFAULT '',
  `be`      varchar(16) NOT NULL DEFAULT '',
  `dr`      varchar(16) NOT NULL DEFAULT '',
  `ready1`  tinyint unsigned NOT NULL DEFAULT 0,
  `ready2`  tinyint unsigned NOT NULL DEFAULT 0,
  `ready3`  tinyint unsigned NOT NULL DEFAULT 0,
  `wapens`  int unsigned NOT NULL DEFAULT 0,
  `kogels`  int unsigned NOT NULL DEFAULT 0,
  `bommen`  int unsigned NOT NULL DEFAULT 0,
  `aantal`  int unsigned NOT NULL DEFAULT 0,
  `auto`    varchar(64) NOT NULL DEFAULT '',
  `autoid`  int unsigned NOT NULL DEFAULT 0,
  `damage`  tinyint unsigned NOT NULL DEFAULT 0,
  `klaar`   tinyint unsigned NOT NULL DEFAULT 0,
  `stad`    varchar(32) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `leider` (`login`),
  KEY `stad` (`stad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `autorace` (
  `id`     int unsigned NOT NULL AUTO_INCREMENT,
  `login`  varchar(16) NOT NULL DEFAULT '',
  `enemy`  varchar(16) NOT NULL DEFAULT '',
  `id1`    int unsigned NOT NULL DEFAULT 0,
  `id2`    int unsigned NOT NULL DEFAULT 0,
  `ready1` tinyint unsigned NOT NULL DEFAULT 0,
  `ready2` tinyint unsigned NOT NULL DEFAULT 0,
  `stad`   varchar(32) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `deelnemers` (`login`, `enemy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `route66` (
  `id`     int unsigned NOT NULL AUTO_INCREMENT,
  `login`  varchar(16) NOT NULL DEFAULT '',
  `driver` varchar(16) NOT NULL DEFAULT '',
  `stad`   varchar(32) NOT NULL DEFAULT '',
  `ready1` tinyint unsigned NOT NULL DEFAULT 0,
  `ready2` tinyint unsigned NOT NULL DEFAULT 0,
  `car`    int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `deelnemers` (`login`, `driver`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hitlist` (
  `id`      int unsigned NOT NULL AUTO_INCREMENT,
  `login`   varchar(16) NOT NULL,
  `suspect` varchar(16) NOT NULL,
  `prijs`   bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `doelwit` (`suspect`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ws` (
  `id`      int unsigned NOT NULL AUTO_INCREMENT,
  `login`   varchar(16) NOT NULL DEFAULT '',
  `victim`  varchar(16) NOT NULL DEFAULT '',
  `suspect` varchar(16) NOT NULL DEFAULT '',
  `prijs`   bigint NOT NULL DEFAULT 0,
  `status`  tinyint unsigned NOT NULL DEFAULT 0,
  `time`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `betrokkenen` (`login`, `victim`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vermoord` (
  `id`    int unsigned NOT NULL AUTO_INCREMENT,
  `login` varchar(16) NOT NULL,
  `dader` varchar(16) NOT NULL DEFAULT '',
  `date`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `msg`   text NULL,
  PRIMARY KEY (`id`),
  KEY `slachtoffer` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `detectives` (
  `id`   int unsigned NOT NULL AUTO_INCREMENT,
  `van`  varchar(16) NOT NULL,
  `naar` varchar(16) NOT NULL,
  `stad` varchar(32) NOT NULL DEFAULT '',
  `time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `afloop` (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- sociaal

CREATE TABLE IF NOT EXISTS `friends` (
  `login`  varchar(16) NOT NULL,
  `friend` varchar(16) NOT NULL,
  PRIMARY KEY (`login`, `friend`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `trouwen` (
  `id`      int unsigned NOT NULL AUTO_INCREMENT,
  `login`   varchar(16) NOT NULL,
  `partner` varchar(16) NOT NULL,
  `stad`    varchar(32) NOT NULL DEFAULT '',
  `ready1`  tinyint unsigned NOT NULL DEFAULT 0,
  `ready2`  tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `paar` (`login`, `partner`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- forum

CREATE TABLE IF NOT EXISTS `forum_topics` (
  `id`      int unsigned NOT NULL AUTO_INCREMENT,
  `type`    varchar(32) NOT NULL DEFAULT '',
  `user`    varchar(16) NOT NULL DEFAULT '',
  `subject` varchar(80) NOT NULL DEFAULT '',
  `message` text NOT NULL,
  `date`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `deelforum` (`type`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_reacties` (
  `id`       int unsigned NOT NULL AUTO_INCREMENT,
  `topic_id` int unsigned NOT NULL DEFAULT 0,
  `user`     varchar(16) NOT NULL DEFAULT '',
  `subject`  varchar(80) NOT NULL DEFAULT '',
  `message`  text NOT NULL,
  `date`     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `bij_topic` (`topic_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll` (
  `id`      int unsigned NOT NULL AUTO_INCREMENT,
  `vraag`   varchar(200) NOT NULL DEFAULT '',
  `actief`  tinyint unsigned NOT NULL DEFAULT 0,
  `datum`   int unsigned NOT NULL DEFAULT 0,
  `keuze1`  varchar(64) NOT NULL DEFAULT '', `antwoord1`  int unsigned NOT NULL DEFAULT 0,
  `keuze2`  varchar(64) NOT NULL DEFAULT '', `antwoord2`  int unsigned NOT NULL DEFAULT 0,
  `keuze3`  varchar(64) NOT NULL DEFAULT '', `antwoord3`  int unsigned NOT NULL DEFAULT 0,
  `keuze4`  varchar(64) NOT NULL DEFAULT '', `antwoord4`  int unsigned NOT NULL DEFAULT 0,
  `keuze5`  varchar(64) NOT NULL DEFAULT '', `antwoord5`  int unsigned NOT NULL DEFAULT 0,
  `keuze6`  varchar(64) NOT NULL DEFAULT '', `antwoord6`  int unsigned NOT NULL DEFAULT 0,
  `keuze7`  varchar(64) NOT NULL DEFAULT '', `antwoord7`  int unsigned NOT NULL DEFAULT 0,
  `keuze8`  varchar(64) NOT NULL DEFAULT '', `antwoord8`  int unsigned NOT NULL DEFAULT 0,
  `keuze9`  varchar(64) NOT NULL DEFAULT '', `antwoord9`  int unsigned NOT NULL DEFAULT 0,
  `keuze10` varchar(64) NOT NULL DEFAULT '', `antwoord10` int unsigned NOT NULL DEFAULT 0,
  `gestemd` text NULL,
  PRIMARY KEY (`id`),
  KEY `actief` (`actief`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wie op welke poll gestemd heeft.
--
-- In de oude versie werden stemmen bijgehouden door de tekst "(ip,keuze)" aan
-- het tekstveld `poll`.`gestemd` te plakken. Dat veld groeide onbeperkt en er
-- moest met string-zoeken in gecontroleerd worden of iemand al gestemd had.
CREATE TABLE IF NOT EXISTS `poll_stemmen` (
  `poll_id` int unsigned NOT NULL,
  `login`   varchar(16) NOT NULL,
  `keuze`   tinyint unsigned NOT NULL,
  `time`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`poll_id`, `login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `news` (
  `id`    int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(120) NOT NULL DEFAULT '',
  `text`  text NOT NULL,
  `time`  int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `tijd` (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ klikmissies

-- Door de admin beheerde links naar externe toplijsten/stemsites, met een
-- optionele automatische callback, een afkoeltijd en een beloning.
CREATE TABLE IF NOT EXISTS `klikmissies` (
  `id`                 int unsigned NOT NULL AUTO_INCREMENT,
  `naam`               varchar(100) NOT NULL DEFAULT '',
  `omschrijving`       varchar(255) NOT NULL DEFAULT '',
  `url`                varchar(500) NOT NULL DEFAULT '', -- mag `{login}` bevatten
  `heeft_callback`     tinyint unsigned NOT NULL DEFAULT 0,
  `nieuw_venster`      tinyint unsigned NOT NULL DEFAULT 1, -- Stem-link/-knop opent in een nieuw tabblad
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

-- Logboek van uitgekeerde klikmissie-beloningen. Bepaalt de cooldown per
-- speler per missie en dient als overzicht voor de admin.
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

-- ---------------------------------------------------------------- beheer

CREATE TABLE IF NOT EXISTS `bans` (
  `id`    int unsigned NOT NULL AUTO_INCREMENT,
  `ip`    varchar(45) NOT NULL DEFAULT '',
  `login` varchar(16) NOT NULL DEFAULT '',
  `reden` varchar(255) NOT NULL DEFAULT '',
  `door`  varchar(16) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `ip` (`ip`),
  KEY `login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `multiple` (
  `ip`   varchar(45) NOT NULL,
  `allo` tinyint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `iplog` (
  `login`  varchar(16) NOT NULL,
  `ip`     varchar(45) NOT NULL,
  `time`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `allo`   tinyint unsigned NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'levend',
  PRIMARY KEY (`login`, `ip`),
  KEY `ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shame` (
  `id`      int unsigned NOT NULL AUTO_INCREMENT,
  `time`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cheater` varchar(16) NOT NULL DEFAULT '',
  `person`  varchar(16) NOT NULL DEFAULT '',
  `com`     varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `logs` (
  `id`     int unsigned NOT NULL AUTO_INCREMENT,
  `time`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `login`  varchar(16) NOT NULL DEFAULT '',
  `person` varchar(16) NOT NULL DEFAULT '',
  `code`   bigint NOT NULL DEFAULT 0,
  `area`   varchar(32) NOT NULL DEFAULT '',
  `com`    varchar(255) NOT NULL DEFAULT 'Geen',
  PRIMARY KEY (`id`),
  KEY `speler` (`login`, `time`),
  KEY `gebied` (`area`, `time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `donate` (
  `id`     int unsigned NOT NULL AUTO_INCREMENT,
  `door`   varchar(16) NOT NULL DEFAULT '',
  `code`   varchar(255) NOT NULL DEFAULT '',
  `status` tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eenmalige codes voor activatie en wachtwoord-vergeten.
CREATE TABLE IF NOT EXISTS `temp` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `time`         datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `login`        varchar(16) NOT NULL DEFAULT '',
  `ip`           varchar(45) NOT NULL DEFAULT '',
  `forwardedFor` varchar(16) NOT NULL DEFAULT '',
  `code`         char(64) NOT NULL DEFAULT '',
  `area`         varchar(32) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `opzoeken` (`area`, `code`),
  KEY `opruimen` (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Instellingen die een beheerder in het spel zelf kan omzetten, zonder in
-- inc/config.php te hoeven duiken. Alles wat hier niet in staat valt terug op
-- de standaardwaarde in de code.
-- `waarde` is een TEXT en geen varchar: de advertentiecode van de beheerder
-- staat er ook in, en die kan een heel scriptblok zijn.
CREATE TABLE IF NOT EXISTS `instellingen` (
  `naam`   varchar(40) NOT NULL,
  `waarde` text NOT NULL,
  PRIMARY KEY (`naam`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dagelijkse momentopname van kerncijfers, voor trendgrafieken op het
-- beheerdashboard. Zonder deze tabel is een cijfer als "geld in omloop
-- vorige week" achteraf niet meer te reconstrueren.
CREATE TABLE IF NOT EXISTS `beheer_geschiedenis` (
  `dag`                 date NOT NULL,
  `spelers`             int unsigned NOT NULL DEFAULT 0,
  `levend`              int unsigned NOT NULL DEFAULT 0,
  `online`              int unsigned NOT NULL DEFAULT 0,
  `geld_totaal`         bigint NOT NULL DEFAULT 0,
  `nieuwe_registraties` int unsigned NOT NULL DEFAULT 0,
  `vast`                int unsigned NOT NULL DEFAULT 0,
  `bans_totaal`         int unsigned NOT NULL DEFAULT 0,
  `families`            int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`dag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tijdstip waarop elke periodieke taak voor het laatst gedraaid heeft.
CREATE TABLE IF NOT EXISTS `cron` (
  `name` varchar(16) NOT NULL,
  `time` datetime NOT NULL DEFAULT '1970-01-01 00:00:01',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================ startdata

INSERT IGNORE INTO `cron` (`name`, `time`) VALUES
  ('kogels',  '1970-01-01 00:00:01'),
  ('uur',     '1970-01-01 00:00:01'),
  ('day',     '1970-01-01 00:00:01'),
  ('week',      '1970-01-01 00:00:01'),
  ('loterij',   '1970-01-01 00:00:01'),
  ('detective', '1970-01-01 00:00:01');

INSERT IGNORE INTO `stad` (`stad`, `kogels`, `prijs`, `drugs`, `drank`, `drugsp`, `drankp`, `transp`, `grond`) VALUES
  -- `transp` is de reisprijs; die stond eerder hardcoded in transport.php.
  ('Brussel',   100, 1273,  32, 464, 14903, 3402, 2000, 1000),
  ('Leuven',    100, 1273, 194, 196, 13734, 1093, 2500, 1000),
  ('Gent',      100, 1273, 104,  17,  7049, 5630, 1500, 1000),
  ('Brugge',    100, 1273, 331,   6,  7190, 3668, 1300, 1000),
  ('Hasselt',   100, 1273, 381, 132, 12659, 1312, 1000, 1000),
  ('Antwerpen', 100, 1273,  33,  28,  6172, 4550, 2250, 1000),
  ('Amsterdam', 100, 1273, 194,   4, 12955, 3408, 3500, 1000),
  ('Enschede',  100, 1273, 544,   0, 10080, 2803, 4500, 1000);

INSERT IGNORE INTO `items` (`id`, `nr`, `type`, `naam`, `aprijs`, `vprijs`, `effect`) VALUES
  (1,  1, 'att',   'Uzi',               25000,   20000,    1.60),
  (2,  2, 'att',   'M16',               50000,   40000,    2.00),
  (3,  1, 'trans', 'Treinabonnement',   25000,   20000, 3600.00),
  (4,  3, 'trans', 'Limousine',        100000,   75000, 1800.00),
  (7,  4, 'att',   'Sniper Rifle',     100000,   80000,    3.20),
  (8,  2, 'trans', 'Taxi',              75000,   60000, 2400.00),
  (9,  3, 'att',   '9mm',               10000,    8000,    1.20),
  (10, 4, 'trans', 'Privé-Jet',        150000,  120000,  900.00),
  (13, 6, 'att',   'Tommy Gun',        140000,  120000,    4.20),
  (14, 5, 'att',   'Magnum Semi Auto',  75000,   60000,    2.50),
  (15, 0, 'def',   'Geen bescherming',      0,       0,    1.00),
  (16, 2, 'def',   'Kogelvrije vest',  100000,   75000,    4.00),
  (17, 1, 'def',   'Kogelvrij schild',  30000,   20000,    2.00);

-- `naam` is de sleutel waarmee garage-, race- en marktcode een wagen opzoekt;
-- `auto` is de naam die de speler ziet. Ze zijn hier bewust gelijk gehouden.
--
-- In de oude sql.sql stond in `naam` de prijs als tekst ('45000') en alleen in
-- `auto` de echte naam. Code die op naam opzocht en code die auto toonde,
-- verwezen daardoor naar verschillende dingen. Twee namen voor hetzelfde ding
-- levert alleen maar verwarring op.
INSERT IGNORE INTO `cars` (`auto`, `naam`, `url`, `waarde`) VALUES
  ('Pontiac',                      'Pontiac',                       'images/autos/pontiac.jpg',                  10000),
  ('Caddillac Sedan',              'Caddillac Sedan',               'images/autos/caddillac_sedan.jpg',          15000),
  ('Dodge Kingsway',               'Dodge Kingsway',                'images/autos/dodge_kingsway.jpg',           25000),
  ('Ford Fairlane',                'Ford Fairlane',                 'images/autos/ford_fairlane.jpg',            30000),
  ('Aston Martin DB3S',            'Aston Martin DB3S',             'images/autos/aston_martin_db3s.jpg',        40000),
  ('Buick Cabrio',                 'Buick Cabrio',                  'images/autos/buick_cabrio.jpg',             45000),
  ('Chrysler Converible',          'Chrysler Converible',           'images/autos/chrysler_converible.jpg',      60000),
  ('Pontiac Bonneville',           'Pontiac Bonneville',            'images/autos/pontiac_bonneville.jpg',       65000),
  ('Dodge Dart',                   'Dodge Dart',                    'images/autos/dodge_dart.jpg',               80000),
  ('Mercury Cougar',               'Mercury Cougar',                'images/autos/mercury_cougar.jpg',           95000),
  ('Chevy Camaro SS',              'Chevy Camaro SS',               'images/autos/chevy_camaro_ss.jpg',         125000),
  ('Ford Mustang',                 'Ford Mustang',                  'images/autos/ford_mustang.jpg',            150000),
  ('Alfa Romeo FNM 2000 JK',       'Alfa Romeo FNM 2000 JK',        'images/autos/alfa_romeo_fnm_2000_jk.jpg',  175000),
  ('Porsche 356B Cabriolet',       'Porsche 356B Cabriolet',        'images/autos/porsche_356b_cabriolet.jpg',  200000),
  ('Mercedes W124 Avus Streamling','Mercedes W124 Avus Streamling', 'images/autos/streamliner.jpg',             400000);

INSERT IGNORE INTO `casino` (`spel`, `owner`, `stad`, `winst`, `inzet`, `status`) VALUES
  ('kogelfabriek', '', '', 0, 1000, 1);
