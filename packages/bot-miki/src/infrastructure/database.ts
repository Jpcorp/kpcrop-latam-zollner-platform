import { Kysely, PostgresDialect, type Generated } from 'kysely';
import { Pool } from 'pg';
import { config } from '../config.js';

// Generated<T> = tipo T en SELECT, opcional en INSERT (columna con DEFAULT en PostgreSQL)
interface Database {
  licenses: {
    id: Generated<string>;
    tenant_id: string;
    subscription_id: string;
    plan: 'starter' | 'growth' | 'agency';
    status: 'active' | 'suspended' | 'cancelled';
    features: unknown;
    max_stores: number;
    api_key: string;
    created_at: Generated<Date>;
    expires_at: Date | null;
    updated_at: Generated<Date>;
  };
  tenant_stores: {
    id: Generated<string>;
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
    created_at: Generated<Date>;
  };
  sync_events: {
    id: Generated<string>;
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
    created_at: Generated<Date>;
  };
  bsale_variant_snapshots: {
    tenant_id: string;
    variant_id: number;
    content_hash: string;
    last_known_data: unknown;
    last_seen_at: Generated<Date>;
  };
  scheduled_jobs: {
    id: Generated<string>;
    store_id: string;
    entity_type: string;
    cron_expression: string;
    active: boolean;
    last_run_at: Date | null;
    last_run_status: string | null;
    next_run_at: Date | null;
  };
  webhook_registrations: {
    id: Generated<string>;
    store_id: string;
    bsale_cpn_id: string;
    topics: string[];
    status: string;
    notes: string | null;
    requested_at: Generated<Date>;
    activated_at: Date | null;
  };
}

export const db = new Kysely<Database>({
  dialect: new PostgresDialect({
    pool: new Pool({ connectionString: config.DATABASE_URL }),
  }),
});
