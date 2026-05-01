ALTER TABLE node_groups
    ADD COLUMN ingress_cdn_host VARCHAR(255) NULL AFTER name;

ALTER TABLE node_groups
    ADD COLUMN ingress_sni_host VARCHAR(255) NULL AFTER ingress_cdn_host;

ALTER TABLE node_groups
    ADD COLUMN ingress_path_strategy VARCHAR(32) NOT NULL DEFAULT 'random' AFTER ingress_sni_host;

ALTER TABLE node_groups
    ADD COLUMN ingress_port_strategy VARCHAR(32) NOT NULL DEFAULT 'client_range_then_target' AFTER ingress_path_strategy;

ALTER TABLE node_groups
    ADD COLUMN ingress_preferred_public_port INT NULL AFTER ingress_port_strategy;
