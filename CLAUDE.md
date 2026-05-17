# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build & Test

**Sync changes to a running pfSense VM** (recommended dev workflow per BOOTSTRAP.md):
```sh
rsync -xav --delete src/usr/local/www/ root@<pfsense-ip>:/usr/local/www/
rsync -xav --delete src/etc/inc/      root@<pfsense-ip>:/etc/inc/
```

**PHP unit tests:**
```sh
./vendor/bin/phpunit
```

**Rector code modernization (AST-based refactoring):**
```sh
./vendor/bin/rector process src/
```

Full image builds (`build.sh`) require a FreeBSD toolchain and are not expected in a standard dev environment.

## Architecture

pfSense is a PHP/FreeBSD firewall with a Bootstrap 3 web GUI.

**Source layout:**
- `src/etc/inc/` — ~54 backend `.inc` files (auth, interfaces, firewall, VPN, etc.)
- `src/usr/local/www/` — web GUI PHP pages (215+ files)
- `src/usr/local/www/classes/` — form builder (`Form.class.php`) and UI helpers
- `src/usr/local/www/includes/` — shared GUI includes
- `src/usr/local/pfSense/include/` — PSR-4 namespaced classes (`pfSense\*`, `Netgate\*`)
- `src/usr/local/www/widgets/` — dashboard widgets (see Widget System below)

**Include hierarchy:** Every GUI page starts with `require_once("guiconfig.inc")`, which bootstraps auth, session, and config. `functions.inc` is a legacy aggregator that loads core backend includes (interfaces, services, certs, etc.).

**Config access:** Use path-based accessors from `config.lib.inc`:
- `config_get_path('system/hostname')`
- `config_set_path('system/hostname', $value)`

**Form pattern:** Use the `Form` builder class, not raw HTML:
```php
require('classes/Form.class.php');
$form = new Form;
$section = new Form_Section('Section Title');
$section->addInput(new Form_Input('field', 'Label', 'text', $value));
$form->add($section);
print($form);
```

**Coding conventions** (from BOOTSTRAP.md):
- Tabs (tabstop=4) for indentation; no trailing whitespace
- No inline JS, no `style`/`align`/`width` HTML attributes, no `&nbsp;`, no tables for layout
- Use `foreach/endforeach` templating syntax over `echo`
- Double-quoted HTML attributes; single-quoted PHP strings
- Icons for status indication; Bootstrap buttons for actions
- Do not refactor backend code at the top of files — only changes required by your feature are acceptable

## Widget System

Widgets are the dashboard panels on `index.php`. Each widget is auto-discovered — no registration step needed.

### File anatomy (two required files per widget)

**`src/usr/local/www/widgets/include/<name>.inc`** — metadata only:
```php
$mywidget_title = gettext("My Widget");
$mywidget_title_link = "status_page.php";          // optional: clickable title
$mywidget_allow_multiple_widget_copies = true;     // optional: allow duplicates
$mywidget_widget_defaults = ['setting' => 'val'];  // optional: default config
```

**`src/usr/local/www/widgets/widgets/<name>.widget.php`** — logic and HTML output.

Optional: **`src/usr/local/www/widgets/javascript/<name>.js`** — loaded only when that widget is active.

### Dashboard-injected globals
When `index.php` includes your widget, these variables are already set:

| Variable | Purpose |
|---|---|
| `$widgetkey` | Unique instance key: `"widgetname-0"`, `"widgetname-1"`, … |
| `$user_settings` | Full user settings array (read widget config from here) |
| `$widget_panel_body_id` | ID for the collapsible panel body div |
| `$widget_panel_footer_id` | ID for the configuration panel footer div |
| `$widget_showallnone_id` | ID for the show-all/none filter button |
| `$widget_first_instance` | `true` if this is the first copy of this widget type |

### Widgetkey validation (required for any POST/GET handler)
```php
if ($_POST['widgetkey'] || $_GET['widgetkey']) {
    $rwidgetkey = $_POST['widgetkey'] ?? $_GET['widgetkey'] ?? null;
    if (is_valid_widgetkey($rwidgetkey, $user_settings, __FILE__)) {
        $widgetkey = $rwidgetkey;
    } else {
        print gettext("Invalid Widget Key");
        exit;
    }
}
```

### AJAX refresh
```php
// PHP side: handle AJAX call and return only the updated fragment
if (isset($_POST['ajax'])) {
    print(compose_table_body_contents($_POST['widgetkey']));
    exit();
}
```
```javascript
// JS side (inside events.push(function() { ... })):
var obj = new Object();
obj.name = "My Widget";
obj.url  = "/widgets/widgets/mywidget.widget.php";
obj.callback = function(html) { $('#my-tbody').html(html); };
obj.parms = { ajax: "ajax", widgetkey: <?=json_encode($widgetkey)?> };
obj.freq  = 30;  // refresh every ~30 seconds
register_ajax(obj);
```

### Saving widget configuration
```php
// In POST handler (after validation):
set_customwidgettitle($user_settings);  // handle optional custom title
$user_settings['widgets'][$widgetkey]['mysetting'] = $validated_value;
save_widget_settings($_SESSION['Username'], $user_settings['widgets'],
    gettext("Saved My Widget settings via Dashboard."));
header("Location: /index.php");
exit;
```

### Configuration panel HTML pattern
```php
// Config panel is appended after your main content and hidden by default
</div><div id="<?=$widget_panel_footer_id?>" class="panel-footer collapse">
    <form action="/widgets/widgets/mywidget.widget.php" method="post">
        <input type="hidden" name="widgetkey" value="<?=htmlspecialchars($widgetkey)?>">
        <?=gen_customwidgettitle_div($widgettitle)?>
        <!-- your settings fields -->
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-save icon-embed-btn"></i>
            <?=gettext("Save")?>
        </button>
    </form>
</div>
```

### Key widget utility functions
- `is_valid_widgetkey($key, $user_settings, __FILE__)` — security check; always call before trusting POST/GET
- `save_widget_settings($username, $settings, $message)` — persist to config (`auth.inc`)
- `gen_customwidgettitle_div($title)` — renders the optional custom title input field
- `set_customwidgettitle(&$user_settings)` — saves custom title from POST data
- `set_widget_checkbox_events(selector, button_id)` — JS helper for show/hide filter checkboxes

### Reference widgets
| Widget | Features demonstrated |
|---|---|
| `carp_status` | Minimal: no config, no AJAX |
| `gateways` | Config panel + AJAX refresh + filter checkboxes |
| `disks` | Advanced: custom PHP class, Nette Html builder, treegrid JS |
| `picture` | File upload, stores image in `/conf/widget_image.<widgetkey>` |
