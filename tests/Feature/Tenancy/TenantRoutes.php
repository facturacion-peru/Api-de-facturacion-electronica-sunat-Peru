<?php

/*
 * Inventario de TODAS las rutas del grupo de empresa (middleware `tenant`) y
 * cómo se prueba el acceso cruzado en CrossTenantAccessTest (principio VIII).
 * TenantRoutesCoverageTest falla si una ruta de empresa no está aquí.
 *
 * Tipos:
 * - resource:  {param} de la empresa B → 404 idéntico a un id inexistente.
 * - list:      solo datos de A, aunque se envíen parámetros que apunten a B.
 * - own:       datos de la propia empresa sin id en la URL; nunca los de B.
 * - write:     crear/modificar apuntando a B no afecta a B.
 * - reference: datos de referencia sin empresa (ubigeo). Exento, con motivo.
 */

return [
    'GET api/v1/auth/me' => ['type' => 'own'],
    'GET api/v1/company' => ['type' => 'own'],
    'PATCH api/v1/company' => ['type' => 'write'],
    'POST api/v1/company/logo' => ['type' => 'write'],
    'GET api/v1/users' => ['type' => 'list'],
    'PATCH api/v1/users/{user}' => ['type' => 'resource', 'param' => 'user'],
    'GET api/v1/invitations' => ['type' => 'list'],
    'POST api/v1/invitations' => ['type' => 'write'],
    'POST api/v1/invitations/{invitation}/resend' => ['type' => 'resource', 'param' => 'invitation'],
    'DELETE api/v1/invitations/{invitation}' => ['type' => 'resource', 'param' => 'invitation'],
    'GET api/v1/audit-logs' => ['type' => 'list'],
    'GET api/v1/catalogs/inventory' => ['type' => 'reference', 'reason' => 'Catálogos SUNAT de unidades, afectaciones y motivos, iguales para todas las empresas'],
    'GET api/v1/products' => ['type' => 'list'],
    'POST api/v1/products' => ['type' => 'write'],
    'GET api/v1/products/{product}' => ['type' => 'resource', 'param' => 'product'],
    'PATCH api/v1/products/{product}' => ['type' => 'resource', 'param' => 'product'],
    'GET api/v1/ubigeos/regiones' => ['type' => 'reference', 'reason' => 'Catálogo oficial de ubigeo, igual para todas las empresas'],
    'GET api/v1/ubigeos/provincias' => ['type' => 'reference', 'reason' => 'Catálogo oficial de ubigeo, igual para todas las empresas'],
    'GET api/v1/ubigeos/distritos' => ['type' => 'reference', 'reason' => 'Catálogo oficial de ubigeo, igual para todas las empresas'],
    'GET api/v1/ubigeos/search' => ['type' => 'reference', 'reason' => 'Catálogo oficial de ubigeo, igual para todas las empresas'],
    'GET api/v1/ubigeos/{id}' => ['type' => 'reference', 'reason' => 'Catálogo oficial de ubigeo, igual para todas las empresas'],
];
