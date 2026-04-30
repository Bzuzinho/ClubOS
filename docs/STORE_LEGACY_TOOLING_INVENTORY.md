# Store Legacy Tooling Inventory

## Current Status

The active `/loja` and `/api/admin/loja` runtime is canonical over `products` and `product_variants`.

The legacy catalog source tables `loja_produtos` and `loja_produto_variantes` were retired after readiness audit confirmed zero rows. Their runtime tooling, models, and seed fixtures were removed from `app/`.

## Remaining Historical Notes

- `product_catalog_migrations` may still exist as historical technical data from the backfill phase
- `LegacyStoreCatalogGuard` remains to block reintroduction of legacy store catalog tokens into active code
- rollout coordination for older environments should apply the dedicated schema drop migration for legacy source tables

## Store Runtime Status

The temporary `store_orders`, `store_order_items`, and `store_cart_items` runtime has already been removed from the active application layer and schema.