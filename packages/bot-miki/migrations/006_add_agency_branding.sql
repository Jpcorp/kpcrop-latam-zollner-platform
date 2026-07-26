-- #56: white-label básico — la agencia puede configurar nombre/logo/color y
-- que el plugin del cliente final lo muestre en vez de la marca genérica.
-- Todas nullable: una licencia sin branding configurado se comporta igual que
-- hoy (fallback a "Synkrop" del lado del plugin).
ALTER TABLE licenses
    ADD COLUMN IF NOT EXISTS agency_name VARCHAR(100),
    ADD COLUMN IF NOT EXISTS agency_logo_url TEXT,
    ADD COLUMN IF NOT EXISTS agency_brand_color VARCHAR(7);
