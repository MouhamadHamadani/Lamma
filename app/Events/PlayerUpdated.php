<?php

namespace App\Events;

/** A player changed their nickname or photo in the lobby (or the host removed the photo). Same safe payload as the other player events. */
class PlayerUpdated extends PlayerEvent {}
