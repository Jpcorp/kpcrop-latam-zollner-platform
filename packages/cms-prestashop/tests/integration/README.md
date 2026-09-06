# Tests de integración (#32)

Corren contra el sandbox **real** de Bsale (no mocks). Excluidos del `composer test`
normal (ver el grupo `integration` en `phpunit.xml`) — requieren red y un token válido.

## Uso

```bash
BSALE_SANDBOX_TOKEN=xxx composer test -- --group integration
```

El token de sandbox se obtiene en `https://account.bsale.dev` (mismo que usa
`ssh/bsale_sandbox.sh` para el resto de las pruebas manuales del proyecto).

Sin `BSALE_SANDBOX_TOKEN` seteado, los tests se **saltan** (no fallan) con un mensaje
explicando cómo activarlos.

## Fixtures relacionadas (#31)

Los productos usados como fixtures en `tests/fixtures/*.json` fueron generados a partir
de datos reales de este mismo sandbox — incluye 2 productos creados a propósito para
cubrir los casos que el sandbox de demo no tenía (`Fixture Camiseta Test`, 3 variantes;
`Fixture Producto Descontinuado`, desactivado vía `DELETE /v1/products/{id}.json`, que
en Bsale es un soft-delete que pone `state=1`, no un borrado real).
