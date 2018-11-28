# [wp-cubi-robo-globalis](https://github.com/globalis-ms/wp-cubi-robo-globalis)

[![Build Status](https://travis-ci.org/globalis-ms/wp-cubi-robo-globalis.svg?branch=master)](https://travis-ci.org/globalis-ms/wp-cubi-robo-globalis)
[![Latest Stable Version](https://poser.pugx.org/globalis/wp-cubi-robo-globalis/v/stable)](https://packagist.org/packages/globalis/wp-cubi-robo-globalis)
[![License](https://poser.pugx.org/globalis/wp-cubi-robo-globalis/license)](https://github.com/globalis-ms/wp-cubi-robo-globalis/blob/master/LICENSE.md)

Globalis Robo commands for wp-cubi

[![wp-cubi](https://github.com/globalis-ms/wp-cubi/raw/master/.resources/wp-cubi-500x175.jpg)](https://github.com/globalis-ms/wp-cubi/)

## Configuration

### Robofile constants

* `THEME_SLUG`: Your theme slug

### Theme structure

```bash
├── assets
│   ├── styles
│   │   ├── *.scss
│   │   ├── **/_*.scss
│   ├── scripts
│   │   ├── _*.map
│   │   ├── **/*.js
│   ├── images
│   └── fonts
├── dist
└── ....
```

**Important :** you have to include files with relative path into yours *.scss/*.map file. One map will create one final file in the building process.

*Sample:*

assets/styles/main.scss
```css
@import "common/variables";
@import "layouts/pages";
@import "layouts/posts";
...
```
assets/scripts/_main.map
```bash
global.js
tools/modals.js
...
```

After build :

* `dist/styles/main.css`
* `dist/scripts/main.js`

## Commands

* `./vendor/bin/robo build:assets [--skip-styles --skip-scripts --skip-images --skip-fonts]`: Build all assets from src folder to dist folder (sass, js, fonts, images)
