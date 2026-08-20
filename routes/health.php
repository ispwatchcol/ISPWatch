<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Chequeo de salud
|--------------------------------------------------------------------------
|
| Archivo aparte, y registrado SIN NINGÚN MIDDLEWARE desde `bootstrap/app.php`.
| Eso no es descuido: es el punto entero de este endpoint.
|
| El grupo `web` arranca la sesión, y la sesión vive en la base de datos
| (`SESSION_DRIVER=database`). El grupo `api` aplica `throttleApi()`, y el
| limitador usa el caché, que también es la base de datos. Cualquiera de los dos
| revienta ANTES de llegar al controlador cuando Postgres no responde — es
| decir, justo en el único momento en que este endpoint tiene algo que decir.
|
| Sin middleware, `/health/deep` puede reportar «la base de datos está caída» en
| lugar de caerse con ella.
|
| El precio es que no hay limitador de peticiones. Se acepta a conciencia: la
| respuesta no contiene secretos, los chequeos son baratos, y se puede exigir un
| token con `HEALTH_CHECK_TOKEN` donde haga falta.
|
*/

Route::get('/health/deep', [HealthController::class, 'deep'])
    ->name('health.deep');
