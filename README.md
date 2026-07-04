<p align="center">
	<img src="./public/impui.svg" alt="IMPUI" width="280">
</p>

<p align="center">
	<em>Immediate Mode-flavored PHP User Interfaces</em>
</p>

---

> [!CAUTION]	
> Active development! WIP

> "Give someone state and they'll have a bug one day, but teach them how to represent state in two separate locations that have to be kept in sync and they'll have bugs for a lifetime." -[ryg](https://twitter.com/rygorous/status/1507178315886444544)

IMPUI is a next generation full-stack SSR framework for building delightfully simple immediate mode flavored HTML user interfaces in PHP. Take PHP and HATEOAS to the next level with IMPUI!

Simplify your frontend logic by shifting state management to the backend. Drive your frontend from the backend using HTML attributes and a hypermedia-driven approach.

From simple CRUD style applications to massive collaborative multiplayer; IMPUI empowers you to build them both. Powered by [Datastar](https://data-star.dev/): *The Hypermedia Framework*.

## Features

- Immediate flavor! Less tedious synchronization and cross cutting concerns.
- Realtime updates! You can update your whole HTML at over 1000 FPS! Should you?
- Run anywhere! Start with PHP-FPM and move to persistent process for low latency push.
- No endpoint explosion! A button fires a named action; no routes, no controllers.

## Usage

```PHP
namespace app\counter;

use vektr3\imp;

class Counter
{
	public int $value = 0;
}

function counter()
{
	$c = imp\state(Counter::class);

	if (imp\action('increment')) {
		$c->value++;
	}
	if (imp\action('decrement')) {
		$c->value--;
	}

	echo counter_html($c);
}

function counter_html(Counter $c): string
{
?>
	<h1>Count <?= esc('something') ?></h1>

	<button data-on:click="@imp('increment')">Increment</button>
	<button data-on:click="@imp('decrement')">Decrement</button>
<? 
}
```