SELECT f.id, f.name, f.client_id, f.status, f.current_mode, f.transport_mode, f.listen_port, f.target_host, f.target_port, f.route_id, f.combination_id, f.speed_limit_mbps, f.active_leg_limit, LEFT(f.bonding_config,300) AS bonding_config_preview FROM forwards f WHERE f.id = 118;
SELECT e.id, e.forward_id, e.node_id, e.host, e.port, e.status, e.dynamic_priority FROM forward_endpoints e WHERE e.forward_id = 118 ORDER BY e.id;
SELECT c.id AS client_id, c.label, c.machine_id, c.last_seen_at, c.version FROM clients c WHERE c.id = (SELECT client_id FROM forwards WHERE id = 118);
