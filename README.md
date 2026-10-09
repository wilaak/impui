# <img src="./public/impui.svg" alt="IMPUI" width="150">

IMPUI (or IMP) is a minimal shim for building immediate mode flavored HTML user interfaces in PHP with Datastar. Simplify your frontend logic by shifting state to the backend. Drive your frontend from the backend using HTML attributes and a hypermedia-driven approach.

## Usage

A basic counter. Truly the example of all examples. Exemplary.

```php
namespace App\Counter;

use Vektr3\Imp;

class Counter
{
    public int $value = 0;
}

function counter()
{
    $c = Imp\state(Counter::class);

    if (Imp\action('increment')) {
        $c->value++;
    }
    if (Imp\action('decrement')) {
        $c->value--;
    }
?>
    <h1>Count <?= Imp\esc($c->value) ?></h1>

    <button data-on:click="@imp('increment')">Increment</button>
    <button data-on:click="@imp('decrement')">Decrement</button>
<?
}
```

IMPUI is a minimal shim intended to slot into your own loop:

```php
use Vektr3\Imp;
use App\Counter;

// Create a new session
$session = new Imp\HostSession(Counter\counter(...));

// Send actions to the session
Imp\host_dispatch($session, 'increment', $signals);

// Render the session
$patch = Imp\host_tick($session);

// Re-render whenever you need to
Imp\host_wake($session);
```