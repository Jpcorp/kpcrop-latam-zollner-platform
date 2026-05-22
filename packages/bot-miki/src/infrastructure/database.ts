import { Kysely, PostgresDialect } from 'kysely';
import { Pool } from 'pg';
import { config } from '../config.js';

// Tipado de todas las tablas de PostgreSQL (refleja database-schema.md)
interface Database {
  licenses: {
    id: string;
    tenant_id: string;
    subscription_id: string;
    plan: 'starter' | 'growth' | 'agency';
    status: 'active' | 'suspended' | 'cancelled';
    features: unknown;       // JSONB: string[]
    max_stores: number;
    api_key: string;
    created_at: Date;
    expires_at: Date | null;
    updated_at: Date;
  };
  tenant_stores: {
    id: string;
    license_id: string;
    store_name: string;
    cms_type: string;
    cms_url: string;
    cms_webhook_secret: string | null;
    bsale_integration_id: number | null;
    bsale_access_token: string | null;
    bsale_price_list_id: number | null;
    bsale_office_id: number | null;
    last_sync_at: Date | null;
    last_sync_status: string | null;
    created_at: Date;
  };
  sync_events: {
    id: string;
    tenant_id: string;
    store_id: string | null;
    sync_type: string;
    entity_type: string;
    status: string;
    records_updated: number;
    records_failed: number;
    duration_ms: number | null;
    error_message: string | null;
    idempotency_key: string | null;
    created_at: Date;
  };
  bsale_variant_snapshots: {
    tenant_id: string;
    variant_id: number;
    content_hash: string;
    last_known_data: unknown;
    last_seen_at: Date;
  };
}

export const db = new Kysely<Database>({
  dialect: new PostgresDialect({
    pool: new Pool({ connectionString: config.DATABASE_URL }),
  }),
});
