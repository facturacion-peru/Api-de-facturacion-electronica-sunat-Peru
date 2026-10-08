<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Tests\Support\SpreadsheetFile;

/*
 * Spec 014 · HU-4.1 (T016): plantillas de importación.
 */

beforeEach(function () {
    $this->company = Company::factory()->withMainEstablishment()->create();
    $this->admin = User::factory()->forCompany($this->company, CompanyRole::CompanyAdmin)->create();
    $this->get = function (string $uri) {
        app('auth')->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken)->get($uri);
    };
});

it('la plantilla de productos trae las columnas, sin filas que importar, y sus instrucciones', function () {
    $response = ($this->get)('/api/v1/imports/products/template');

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('plantilla-productos.xlsx');

    $content = $response->streamedContent();
    $path = sys_get_temp_dir().'/plantilla-'.bin2hex(random_bytes(4)).'.xlsx';
    file_put_contents($path, $content);
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $styles = $zip->getFromName('xl/styles.xml');
    $zip->close();
    @unlink($path);

    // Encabezado y 2 000 filas vacías con formato de texto en «codigo» (columna A) y «lote» (L).
    expect($sheet)->toContain('<t>codigo</t>')->toContain('<t>vencimiento</t>')
        ->and(substr_count($sheet, '<c r="A'))->toBe(2001)
        ->and(substr_count($sheet, '<c r="L'))->toBe(2001)
        ->and($styles)->toContain('numFmtId="49"'); // «@», el formato de texto integrado de Excel

    $sheets = SpreadsheetFile::sheets(($this->get)('/api/v1/imports/products/template'));
    expect(array_keys($sheets))->toBe(['productos', 'instrucciones'])
        ->and($sheets['productos'])->toBe([])
        ->and(array_column($sheets['instrucciones'], 'columna'))->toContain('codigo', 'afectacion_igv', 'stock_inicial')
        ->and(collect($sheets['instrucciones'])->firstWhere('columna', 'unidad')['valores'])->toContain('NIU (Unidad)');
});

it('la plantilla de clientes trae sus columnas e instrucciones', function () {
    $sheets = SpreadsheetFile::sheets(($this->get)('/api/v1/imports/customers/template'));

    expect(array_keys($sheets))->toBe(['clientes', 'instrucciones'])
        ->and(array_column($sheets['instrucciones'], 'columna'))->toContain('tipo_documento', 'numero_documento', 'nombre', 'direccion');
});

it('un tipo desconocido no existe', function () {
    app('auth')->forgetGuards();
    $this->withToken($this->admin->createToken('t')->plainTextToken)->getJson('/api/v1/imports/users/template')->assertNotFound();
});
