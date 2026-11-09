ALTER TABLE payments
  ADD COLUMN provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
  ADD COLUMN provider_reference VARCHAR(120) NULL,
  ADD COLUMN plan VARCHAR(20) NULL,
  ADD UNIQUE KEY payments_provider_reference_uq (provider_reference);

ALTER TABLE subscriptions
  ADD COLUMN provider_reference VARCHAR(120) NULL,
  ADD COLUMN expires_at DATETIME NULL,
  ADD UNIQUE KEY subscriptions_provider_reference_uq (provider_reference);

UPDATE subscriptions
SET status = 'expired'
WHERE status = 'active' AND stripe_sub_id IS NULL AND expires_at IS NULL;
