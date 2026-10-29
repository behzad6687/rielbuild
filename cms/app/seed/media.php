<?php
/**
 * The starter photos, imported into the media library by the installer (Media::importFile), so the client
 * sees and replaces them in Admin > Media. key => file (media.original_name), src (under assets/img/), alt.
 * Other seed files name a photo by its key.
 */
defined('ICM') or exit;

return [
    'home-renovation' => ['file' => 'home-renovation.jpg', 'src' => 'stills/home-renovation.jpg', 'alt' => 'Open-concept main floor after a full home renovation'],
    'kitchen'         => ['file' => 'kitchen.jpg', 'src' => 'stills/kitchen.jpg', 'alt' => 'White oak kitchen with a stone island'],
    'kitchen-2'       => ['file' => 'kitchen-2.jpg', 'src' => 'stills/kitchen-2.jpg', 'alt' => 'Galley kitchen with brass details'],
    'bathroom'        => ['file' => 'bathroom.jpg', 'src' => 'stills/bathroom.jpg', 'alt' => 'Spa ensuite with a walk-in shower'],
    'powder-room'     => ['file' => 'powder-room.jpg', 'src' => 'stills/powder-room.jpg', 'alt' => 'Powder room in green plaster'],
    'basement'        => ['file' => 'basement.jpg', 'src' => 'stills/basement.jpg', 'alt' => 'Finished basement family lounge with a fireplace wall'],
    'basement-bar'    => ['file' => 'basement-bar.jpg', 'src' => 'stills/basement-bar.jpg', 'alt' => 'Basement wet bar and games room'],
    'mudroom'         => ['file' => 'mudroom.jpg', 'src' => 'stills/mudroom.jpg', 'alt' => 'Mudroom with built-in benches'],
    'planning'        => ['file' => 'planning.jpg', 'src' => 'stills/planning.jpg', 'alt' => 'Renovation plans and samples on a work table'],
    'step-talk'       => ['file' => 'step-talk.jpg', 'src' => 'stills/step-talk.jpg', 'alt' => 'A first visit at the kitchen table, plans and samples laid out'],
    'step-design'     => ['file' => 'step-design.jpg', 'src' => 'stills/step-design.jpg', 'alt' => 'Plans, material samples and a level on a white oak work table'],
    'step-build'      => ['file' => 'step-build.jpg', 'src' => 'stills/step-build.jpg', 'alt' => 'Framing and drywall going up during a renovation'],
    'step-walk'       => ['file' => 'step-walk.jpg', 'src' => 'stills/step-walk.jpg', 'alt' => 'A finished room ready for the final walkthrough'],
    'hero-start'      => ['file' => 'hero-start.jpg', 'src' => 'hero-start.jpg', 'alt' => 'A GTA house on a quiet street at dusk'],
    'hero-ending'     => ['file' => 'hero-ending.jpg', 'src' => 'hero-ending.jpg', 'alt' => 'A finished basement, warm and bright'],
];
