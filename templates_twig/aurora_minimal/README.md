# Aurora Minimal

Aurora Minimal is a pared-down variant of the Aurora skin designed for deployments that prefer a clean, text-first presentation. It ships without decorative image assets, relying on a neutral palette and simple typography so it loads quickly and adapts cleanly to constrained hosting environments.

## Key differences
- No background or navigation imagery; the layout uses semantic HTML and system fonts for a lightweight footprint.
- Streamlined navigation markup that avoids decorative wrappers, making it easier to customise with your own CSS.
- Balanced styling that keeps the interface legible across desktop, tablet, and mobile viewports.

## Installation
1. Copy the `aurora_minimal` directory into your `templates_twig` folder if it is not already present.
2. Clear any cached Twig templates in your LotGD installation to ensure the new skin is detected. Depending on your setup this may involve deleting the `/templates_c` directory or running your cache-clear command.

## Selecting the skin
1. Log in to your game as an administrator.
2. Navigate to the "Game Settings" or "Template" configuration section (location varies with your core version).
3. Choose **Aurora Minimal** from the list of available Twig templates and save your changes.

The site will update immediately after saving. If players have personal template overrides, remind them to switch to *Aurora Minimal* from their preferences screen.
