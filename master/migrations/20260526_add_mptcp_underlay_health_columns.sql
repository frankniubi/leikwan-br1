ALTER TABLE service_endpoints
    ADD COLUMN last_chain_latency_ms DECIMAL(10,3) NULL AFTER last_error,
    ADD COLUMN best_latency_ms DECIMAL(10,3) NULL AFTER last_chain_latency_ms;
