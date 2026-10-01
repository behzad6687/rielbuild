<?php
/* Site content: services, FAQ, areas, process. One place to edit copy. */

$SERVICES = array(
    'home-renovation' => array(
        'name'  => 'Full Home Renovation',
        'short' => 'Home Renovation',
        'icon'  => 'house',
        'img'   => 'stills/home-renovation',
        'tag'   => 'One room or the whole house, planned and built by one crew.',
        'title' => 'Full Home Renovation in Toronto & the GTA',
        'desc'  => 'Whole-home and multi-room renovations across the GTA. One written, fixed quote, one project lead, payments tied to finished work.',
        'lead'  => 'Some homes need one room fixed. Others need everything opened up and rethought. Either way, you get one crew, one plan and one person running it from the first measurement to the final walkthrough.',
        'includes' => array(
            'Layout changes and wall removals, with engineering where needed',
            'Framing, drywall, trim, doors and paint',
            'Plumbing and electrical, done by licensed trades',
            'Flooring and tile throughout',
            'Kitchens and bathrooms as part of the whole plan',
            'Permits handled for you',
        ),
        'time' => 'Most whole-home projects run 3 to 6 months. We give you a written timeline before work starts.',
        'faq' => array(
            array('Do we need to move out?', 'For a full gut renovation, usually yes, for part of the job. For a few rooms at a time, most families stay. We plan the order of work around how you live.'),
            array('Can we renovate in phases?', 'Yes. Many clients do the kitchen and main floor first, then the basement or bathrooms the next year. We design it as one plan so the phases fit together.'),
        ),
    ),
    'kitchen-renovation' => array(
        'name'  => 'Kitchen Renovation',
        'short' => 'Kitchens',
        'icon'  => 'kitchen',
        'img'   => 'stills/kitchen',
        'tag'   => 'Cabinets, counters, plumbing, lighting. One crew handles all of it.',
        'title' => 'Kitchen Renovation Contractor in Toronto & the GTA',
        'desc'  => 'Custom kitchen renovations in the GTA: cabinets, counters, plumbing, electrical, flooring and tile, all under one fixed written quote.',
        'lead'  => 'The kitchen is where the day starts and ends. We build kitchens that work hard and look calm: good storage, good light, counters with room to actually cook.',
        'includes' => array(
            'Layout planning and design, including open-concept changes',
            'Custom and semi-custom cabinetry, shelving and wall units',
            'Quartz, stone and wood countertops',
            'New sinks, taps and appliance hookups',
            'Plumbing and electrical, including new lighting',
            'Flooring, backsplash and tiling',
        ),
        'time' => 'A typical kitchen takes 4 to 8 weeks once materials are on site.',
        'faq' => array(
            array('Can we keep using the kitchen during the work?', 'Not the room itself, but we can set up a simple temporary kitchen elsewhere in the house so you still have a fridge, microwave and sink nearby.'),
            array('Do you supply cabinets or can we buy our own?', 'Either works. We can source and install cabinets through our suppliers, or install ones you pick. We will tell you honestly if a product will cause problems.'),
        ),
    ),
    'bathroom-renovation' => array(
        'name'  => 'Bathroom Renovation',
        'short' => 'Bathrooms',
        'icon'  => 'bath',
        'img'   => 'stills/bathroom',
        'tag'   => 'Waterproofed properly, tiled cleanly, finished in weeks.',
        'title' => 'Bathroom Renovation in Toronto & the GTA',
        'desc'  => 'Bathroom and ensuite renovations across the GTA. Proper waterproofing, clean tile work, new fixtures, done in weeks with a fixed written quote.',
        'lead'  => 'A bathroom is small, but it hides a lot of risk behind the tile. We take it down to the studs when it needs it, waterproof it properly, and finish it so it stays beautiful for years.',
        'includes' => array(
            'Full tear-out down to the studs when needed',
            'Waterproofing behind every wet wall',
            'Walk-in showers, glass enclosures and soaker tubs',
            'Vanities, toilets, sinks and taps',
            'Floor and wall tile, heated floors',
            'Lighting, fans and electrical',
        ),
        'time' => 'Most bathrooms take about 2 to 3 weeks. Larger ensuites can take a little longer.',
        'faq' => array(
            array('We only have one bathroom. What happens?', 'Tell us early. We plan the work so the toilet is out of action for as short a time as possible, and we can arrange a temporary option if needed.'),
            array('Do you do heated floors?', 'Yes. Electric in-floor heat is a common add-on and is installed under the tile before it goes down.'),
        ),
    ),
    'basement-finishing' => array(
        'name'  => 'Basement Finishing',
        'short' => 'Basements',
        'icon'  => 'basement',
        'img'   => 'stills/basement',
        'tag'   => 'Basements finished into rooms your family actually uses.',
        'title' => 'Basement Finishing & Renovation in Toronto & the GTA',
        'desc'  => 'Basement finishing and renovation in the GTA: family rooms, guest suites, home gyms and offices. Dry, warm, permitted and built right.',
        'lead'  => 'Your basement is the biggest room you are not using yet. We turn it into a warm, dry, bright space: a family room, a guest suite, a gym or an office.',
        'includes' => array(
            'Moisture check and fixes before anything is closed in',
            'Insulation, framing and drywall',
            'Pot lights, electrical and heating',
            'Bathrooms and wet bars',
            'Flooring, trim, doors and paint',
            'Permits and inspections handled',
        ),
        'time' => 'A typical basement takes 6 to 10 weeks, depending on bathrooms and permits.',
        'faq' => array(
            array('Do I need a permit to finish my basement?', 'In most GTA cities, yes, especially if you add a bathroom or change walls. We handle the drawings and the permit for you.'),
            array('My basement gets a little damp. Can you still finish it?', 'Yes, but we fix the moisture first. Closing in a damp basement is how mould happens, and we will not do it.'),
        ),
    ),
);

$FAQ = array(
    array('How much do I pay up front?', 'A small deposit to book your start date. After that, every payment is tied to a finished stage of work that you can see with your own eyes. You never pay for work that has not been done.'),
    array('Will I get a written quote?', 'Always. After we visit and understand the job, you get a fixed, written quote that lists what is included. If something changes, we agree on it in writing before we do it.'),
    array('How long will my renovation take?', 'It depends on the size. A bathroom is about 2 to 3 weeks, a kitchen 4 to 8 weeks, a basement 6 to 10 weeks and a full home 3 to 6 months. You get a written timeline before we start.'),
    array('How much will it cost?', 'It depends on the size and the finishes you choose. Book a free consultation and we will give you an honest range on the spot, then a fixed written quote.'),
    array('Do I need to move out during the work?', 'For one room or a bathroom, usually not. For a major renovation, it can be easier to stay elsewhere for part of it. We plan the work around your family, kids and pets.'),
    array('What if I change my mind halfway?', 'Changes are welcome. Before any change, we tell you what it does to the price and the timeline, and we only go ahead once you agree in writing.'),
    array('Do you handle permits?', 'Yes. We prepare the drawings, apply for permits and book the inspections, so you do not have to deal with the city.'),
    array('Who will I talk to during the project?', 'One project lead, from start to finish. You get their direct number, and they pick up.'),
    array('How do you deal with dust and mess?', 'We seal off the work area, protect your floors and furniture, and clean up at the end of every day. Your home should still feel like your home.'),
);

$AREAS = array('Toronto', 'North York', 'Etobicoke', 'Scarborough', 'East York', 'Vaughan', 'Richmond Hill', 'Markham', 'Thornhill', 'Mississauga', 'Oakville', 'Brampton', 'Aurora', 'Newmarket', 'Pickering', 'Ajax');

$PROCESS = array(
    array('01', 'Talk', 'A free visit to your home. We listen, measure and give you an honest price range before we leave.', 'stills/step-talk'),
    array('02', 'Design and quote', 'We plan the layout and finishes with you, then hand you a fixed, written quote and timeline.', 'stills/step-design'),
    array('03', 'Build', 'One project lead runs the job. The site is protected, tidy every evening, and you always know what is next.', 'stills/step-build'),
    array('04', 'Walk through', 'We walk the finished space together, fix anything on the list, and hand over a written warranty.', 'stills/step-walk'),
);

$PROMISES = array(
    array('A fixed, written quote', 'The price we write down is the price. Changes only happen when you agree in writing.'),
    array('Payments tied to finished work', 'A small deposit, then you pay as each stage is done and you have seen it.'),
    array('One project lead, start to finish', 'One person who knows your job, with a direct number that gets answered.'),
    array('A clean site every day', 'Floors covered, dust sealed off, and the day swept up before we leave.'),
);

$PROJECTS = array(
    array('stills/kitchen', 'White oak kitchen with stone island', 'Kitchen', 'kitchen'),
    array('stills/basement', 'Family lounge with fireplace wall', 'Basement', 'basement'),
    array('stills/bathroom', 'Spa ensuite with walk-in shower', 'Bathroom', 'bathroom'),
    array('stills/home-renovation', 'Open-concept main floor', 'Full home', 'home'),
    array('stills/powder-room', 'Powder room in green plaster', 'Bathroom', 'bathroom'),
    array('stills/mudroom', 'Mudroom with built-in benches', 'Full home', 'home'),
    array('stills/basement-bar', 'Basement wet bar and games room', 'Basement', 'basement'),
    array('stills/kitchen-2', 'Galley kitchen with brass details', 'Kitchen', 'kitchen'),
);
