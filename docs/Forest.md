# Forest Namespace

The `\Lotgd\Forest` namespace currently houses logic related to battles fought in the forest area.  While small, it demonstrates how specialised gameplay helpers are separated from the legacy global functions.

## Outcomes

`Outcomes` defines two static methods: `victory()` and `defeat()`.  They are invoked by combat code after a player wins or loses against forest creatures.  Both methods interact with multiple classes from the root namespace:

- `AddNews` to announce results on the news page.
- `Battle` for combat messages.
- `PageParts` and `Nav` to show navigation links after combat.
- `Settings` and `Translator` to honour configuration and localisation.

A simplified example of rewarding a player after battle:

```php
$enemy = ['creaturename' => 'Goblin', 'creaturegold' => 50, 'creatureexp' => 30];
Lotgd\Forest\Outcomes::victory([$enemy]);
```

The function grants gold and experience based on the passed creature data and may award a flawless bonus when appropriate.

## Forest Wrapper

In addition to the `Outcomes` class the root namespace provides `Lotgd\Forest` – a thin wrapper that renders the classic forest navigation.  Modules can call `Lotgd\Forest::forest()` to display the default forest page with links created by `Lotgd\Nav`.

```php
Lotgd\Forest::forest();
```

Future combat or exploration related helpers may be added to this namespace.

### Interaction With Modules

`Outcomes::victory()` and `Outcomes::defeat()` trigger several module hooks such as `forest_victory` and `forest_defeat`. Modules can adjust gold or experience rewards through these hooks.

### Entry Points

The classic `forest.php` page primarily calls `Lotgd\Forest::forest()` to set up the navigation and to pick random encounters. Modules may supply additional creatures or special events during this step.


