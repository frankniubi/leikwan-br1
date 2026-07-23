-- Dedicated LeiGod forward-node combinations do not require a sidecar. Aggregation
-- combinations (bond/MPTCP/CF) are still enforced by ProvisioningService.
ALTER TABLE forward_combinations
    MODIFY COLUMN sidecar_point_id BIGINT UNSIGNED NULL;
