/* Run once after the existing governance/OLA migrations. The instant priority is retired. */
UPDATE service_catalog SET default_priority = 'urgent' WHERE default_priority = 'instant';
UPDATE tickets SET priority = 'urgent' WHERE priority = 'instant';
UPDATE ola_policies SET priority = 'urgent' WHERE priority = 'instant';
UPDATE problem_records SET priority = 'urgent' WHERE priority = 'instant';

ALTER TABLE service_catalog MODIFY default_priority ENUM('normal','urgent','critical') NOT NULL DEFAULT 'normal';
ALTER TABLE tickets MODIFY priority ENUM('normal','urgent','critical') NOT NULL DEFAULT 'normal';
ALTER TABLE ola_policies MODIFY priority ENUM('normal','urgent','critical') NOT NULL;
ALTER TABLE problem_records MODIFY priority ENUM('normal','urgent','critical') NOT NULL DEFAULT 'normal';
