<?
	require_once("cards.php");

	class SevenWonders {
		public $name;
		public $maxplayers = 7;
		public $players = array();
		public $creator;
		public $gameID;
		public $deck;
		public $server;
		public $started = false;
		public $age = 1;
		public $turn = 1;
		public $cardsChosen = array();
		public $wonders = array(
			array(
				"name" => "Olympia"
			), // need wonder data
			array(
				"name" => "Rhodes"
			),
			array(
				"name" => "Alexandria"
			),
			array(
				"name" => "Ephesos"
			),
			array(
				"name" => "Halicarnassus"
			),
			array(
				"name" => "Giza"
			),
			array(
				"name" => "Babylon"
			)
		);

		public function __construct(){
			global $deck;
			$this->deck = $deck;
		}

		public function addPlayer(IWebSocketConnection $user){
			if(count($this->players) == 0) $this->creator = $user;
			$this->players[] = $user;
			$user->game = $this;
			
			$packet = packet(array('id' => $user->getId(), 'name' => $user->name), 'newplayer');
            foreach($this->players as $player){
                    if($player != $user){
                            $player->sendString($packet);   
                            $user->sendString(packet(array('id' => $player->getId(), 'name' => $player->name), 'newplayer'));   
                    } 
            }

            // tell the players we're starting the game
            if(count($this->players) == $this->maxplayers){
            	$this->started = true;
            	$packet = packet(array('id' => $this->id), 'started');
             	$this->server->broadcastAll($packet);

                // give everyone some money/wonders to start
				$wonderKeys = array_keys($this->wonders);
				foreach($this->players as $player){
					$player->coins = 3;
					$wonder = array_rand($wonderKeys);
					$player->wonder = $this->wonders[$wonderKeys[$wonder]];
					unset($wonderKeys[$wonder]);
				}

				// shuffle order of players
				shuffle($this->players);
				$playerInfo = array();
				for($i = 0; $i < count($this->players); $i++){
					$this->players[$i]->order = $i + 1;
					$playerInfo[] = array(
						'id' => $this->players[$i]->getId(),
						'name' => $this->players[$i]->name,
						'order' => $this->players[$i]->order
					);
				}

				// send start information
				foreach($this->players as $player){
					$startInfo = array(
						"coins" => 3,
						"wonder" => $player->wonder["name"],
						"plinfo" => $playerInfo
					);
					$player->sendString(packet($startInfo, "startinfo"));
				}

				// deal the cards
				$this->deck->deal(1, $this->players);
            }
		}

		public function getPlayerById($id){
			foreach($this->players as $player)
				if($player->getId() == $id) return $player;
		}

		public function removePlayer(IWebSocketConnection $user){
			// drop player from game
			unset($this->players[array_search($user, $this->players)]);
		}

		public function broadcast($packet, $exclude = false){
			foreach($this->players as $player) 
				if(!$exclude || $player != $exclude) $player->sendString($packet);
		}

		public function rotateHands($moveLeft){
			if($moveLeft){
				$temp = $this->players[count($this->players) - 1]->hand;
				for($i = count($this->players) - 1; $i >= 0; $i--){
					$this->players[$i]->hand = $i == 0 ? $temp : $this->players[$i - 1]->hand;
					$packet = packet(array('cards' => $this->deck->cardNames($this->players[$i]->hand)), "hand");
					$this->players[$i]->sendString($packet);
				}
			} else {
				$temp = $this->players[0]->hand;
				for($i = 0; $i < count($this->players); $i++){
					$this->players[$i]->hand = $i == count($this->players) - 1 ? $temp : $this->players[$i + 1]->hand;
					$packet = packet(array('cards' => $this->deck->cardNames($this->players[$i]->hand)), "hand");
					$this->players[$i]->sendString($packet);
				}
			}
		}

		public function onMessage(IWebSocketConnection $user, $args){
			switch($args['messageType']){
				case 'cardplay':
					$cardName = $args['value'][0];
					// match what they sent with a Card object
					foreach($user->hand as $card)
					{
						if($card->getName() == $cardName) $foundCard = $card;
					}
					if(isset($foundCard)){
						if($args['value'][1] == 'trash' or $user->canPlayCard($foundCard, true)){
							$user->isTrashing = $args['value'][1] == 'trash';
							$this->cardsChosen[$user->getId()] = $foundCard;
							$user->selectedCard = $foundCard;
							// if everyone's played a card, execute cards and redeal/new turn
							if(count($this->cardsChosen) == count($this->players)){
								// execute effects of all played cards
								foreach($this->players as $player){
									if($player->isTrashing){
										$player->addCoins(3);
										$player->isTrashing = false;
									} else {
										$player->selectedCard->play($user, array() /*, more args here? */);
										$player->cardsPlayed[] = $player->selectedCard;
									}
									unset($player->hand[array_search($player->selectedCard, $player->hand)]);
									unset($player->selectedCard);
								}

								if($this->turn == 6){
									// go into a new age
									$this->age++;
									$this->turn = 1;
									$this->deck->deal($this->age, $this->players);
								} else {
									// change hands and start a new turn
									$this->turn++;
									$this->broadcast(packet(array('cards' => $this->deck->cardNames($this->cardsChosen)), "cardschosen"));
									$this->cardsChosen = array();
									$this->rotateHands($this->age != 2);
								}
							}
						} else {
							// error: user can't play the card
						}
					} else {
						// error: you cheating motherfucker, you don't have that card in your hand
					}
				break;

				case 'cardignore':
					$user->isTrashing = false;
					unset($user->selectedHard);
					unset($this->cardsChosen[$user->getId()]);
				break;

				default:
					print_r($args);
				break;
			}
		}
	}
?>

