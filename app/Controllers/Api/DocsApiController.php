<?php

namespace App\Controllers\Api;

use CodeIgniter\Controller;

/** Public: the OpenAPI document and a Swagger UI page that renders it. No auth. */
class DocsApiController extends Controller
{
    private const SPEC = ROOTPATH . 'docs/api/openapi.json';

    public function spec()
    {
        if (! is_file(self::SPEC)) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'openapi.json not found']);
        }
        $spec = json_decode((string) file_get_contents(self::SPEC), true);
        if (! is_array($spec)) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'openapi.json is not valid JSON']);
        }
        // Point the document at this installation.
        $spec['servers'] = [['url' => rtrim(site_url('api'), '/'), 'description' => 'This TicketHub']];

        return $this->response
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('Access-Control-Allow-Origin', '*')
            ->setBody(json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function docs()
    {
        $specUrl = site_url('api/openapi.json');
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>TicketHub API</title>'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui.min.css">'
            . '<style>body{margin:0;background:#fff}.topbar{display:none}</style></head><body>'
            . '<div id="swagger-ui"></div>'
            . '<script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui-bundle.min.js"></script>'
            . '<script>window.onload=function(){window.ui=SwaggerUIBundle({url:' . json_encode($specUrl) . ',dom_id:"#swagger-ui",deepLinking:true,persistAuthorization:true,tryItOutEnabled:true});};</script>'
            . '</body></html>';

        return $this->response->setHeader('Content-Type', 'text/html; charset=utf-8')->setBody($html);
    }
}
