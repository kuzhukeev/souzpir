<?php

// One source is shared with the frontend; adding a screen does not require
// keeping two copies of its path and label in sync.
return json_decode(file_get_contents(base_path('resources/js/components/navigation/navigation.json')), true, flags: JSON_THROW_ON_ERROR);
