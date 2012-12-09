<?php

require_once("cards.php");

class SevenWonders {
    public $debug = true;
    public $name;
    public $players = array();
    public $maxplayers;
    public $creator;
    public $id;
    public $deck;
    public $server;
    public $started = false;
    public $age = 1;
    public $turn = 1;
    public $cardsChosen = array();
    public $wonders;
    public $playerInfo;

    public function __construct(){
        global $deck;
        $this->deck = $deck;
        $this->wonders = $this->loadWonders();
    }

    private function loadWonders() {
        $wonders = json_decode(file_get_contents("cards/wonders.json"), true);
        foreach ($wonders as &$wonder) {
            foreach ($wonder as $side => &$value) {
                if ($side == 'name')
                    continue;

                if (isset($value['resource']))
                    $value['resource'] =
                        WonderCard::csvResources($value['resource'], false)[0];

                foreach ($value['stages'] as &$stage) {
                    if (isset($stage['resource']))
                        $stage['resource'] =
                            WonderCard::csvResources($stage['resource'], false)[0];
                    $stage['requirements'] =
                        WonderCard::csvResources($stage['requirements'], false);
                }
            }
        }
        return $wonders;
    }

    public function log($msg){
        if($this->debug)
            echo "Game {$this->id} (turn {$this->turn}, age {$this->age}): $msg\n";
    }

    public function addPlayer(Player $user){
        foreach($this->players as $player){
            $player->send('newplayer', array('id' => $user->id(),
                                             'name' => $user->name()));
            $user->send('newplayer', array('id' => $player->id(),
                                           'name' => $player->name()));
        }

        if(count($this->players) == 0)
            $this->creator = $user;
        $this->players[] = $user;
        $user->setGame($this);

        if (count($this->players) == $this->maxplayers)
            $this->startGame();
    }

    public function startGame() {
        // tell the players we're starting the game
        $this->started = true;
        $this->server->broadcast('started', array('id' => $this->id));

        // set up the start conditions
        $wonderKeys = array_keys($this->wonders);
        shuffle($wonderKeys);
        foreach($this->players as $player){
            // starting moneyz
            $player->coins = 3;

            // select a wonder
            $wonder = $this->wonders[array_pop($wonderKeys)];
            $player->wonderName = $wonder['name'];
            $player->wonder = $wonder['a']; // TODO: not here
            if (isset($wonder['resource']))
                $player->addResource($wonder['resource']);
        }

        // shuffle order of players
        shuffle($this->players);

        $playerInfo = array();
        for($i = 0; $i < count($this->players); $i++){
            $this->players[$i]->leftPlayer = $i == 0 ? $this->players[count($this->players) - 1] : $this->players[$i - 1];
            $this->players[$i]->rightPlayer = $i == (count($this->players) - 1) ? $this->players[0] : $this->players[$i + 1];
            $this->players[$i]->order = $i + 1;
            $playerInfo[] = array(
                'id' => $this->players[$i]->id(),
                'name' => $this->players[$i]->name(),
                'order' => $this->players[$i]->order
            );
        }
        $this->playerInfo = $playerInfo;

        // send start information
        foreach($this->players as $player){
            $player->sendStartInfo($playerInfo);
        }

        $this->deal();
    }

    public function deal() {
        $this->deck->deal($this->age, $this->players);
        foreach ($this->players as $player)
            $player->sendHand();
    }

    public function removePlayer(Player $user){
        // drop player from game
        unset($this->players[array_search($user, $this->players)]);
    }

    public function broadcast($type, $msg){
        foreach($this->players as $player)
            $player->send($type, $msg);
    }

    public function rotateHands($moveLeft){
        $neighbor = $this->players[0];
        $last = $neighbor->hand;
        do {
            $player = $moveLeft ? $neighbor->leftPlayer : $neighbor->rightPlayer;
            $tmp = $last;
            $last = $player->hand;
            $player->hand = $tmp;
            $player->sendHand();
            $neighbor = $player;
        } while($neighbor != $this->players[0]);
    }

    private function playCards() {
        $this->log("All cards found");

        // execute effects of all played cards
        foreach ($this->players as $player) {
            $data = array();
            if (!$player->leftPlayer->isTrashing)
                $data['left'] = $player->leftPlayer->selectedCard->json();
            if (!$player->rightPlayer->isTrashing)
                $data['right'] = $player->rightPlayer->selectedCard->json();
            $player->send('cardschosen', $data);

            $card = $player->selectedCard;
            if ($player->isTrashing) {
                $this->log("{$player->info()} trashing " . $card->getName());
                $player->addCoins(3);
                $player->isTrashing = false;
            //} elseif($player->isWonderBuilding == true){
            } else {
                $this->log("{$player->info()}) playing " . $card->getName());
                $card->play($player);
                $player->cardsPlayed[] = $card;

                // Consume money cost for this player and pay adjacent players
                $player->addCoins(-1 * $card->getMoneyCost());
                foreach ($player->pendingCost as $dir => $cost) {
                    if ($dir == 'left')
                        $player->leftPlayer->addCoins($cost);
                    else if ($dir == 'right')
                        $player->rightPlayer->addCoins($cost);
                    $player->addCoins(-1 * $cost);
                }
            }
            unset($player->hand[array_search($card, $player->hand)]);
        }

        foreach ($this->players as $player) {
            unset($player->selectedCard);
            unset($player->pendingCost);
        }

        $this->cardsChosen = array();
        if ($this->turn == 6) {
            // go into a new age
            $this->log("Ending age {$this->age}");

            $this->log("Evaluating military");
            foreach ($this->players as $player) {
                $player->evaluateMilitary($this->age);
            }

            $this->age++;
            $this->turn = 1;
            $this->deal();
        } else {
            // change hands and start a new turn
            $this->log("Ending turn " . $this->turn);
            $this->turn++;
            $this->rotateHands($this->age != 2);
        }

        foreach($this->players as $player){
            $this->log("{$player->info()} has {$player->calcPoints()} points");
        }
    }

    public function onMessage(Player $user, $args){
        switch($args['messageType']){
            case 'cardplay':
                $cardName = $args['value'][0];
                // match what they sent with a Card object
                foreach ($user->hand as $card) {
                    if($card->getName() == $cardName) $foundCard = $card;
                }
                if (!isset($foundCard)) // don't have the specified card
                    break;
                if ($args['value'][1] == 'trash') {
                    $user->isTrashing = true;
                    unset($user->pendingCost);
                } else {
                    // TODO: this '0' should be an input from the message
                    $cost = $user->cardCost($foundCard, $args['value'][2]);
                    if ($cost === false)
                        break;
                    $user->isTrashing = false;
                    $user->pendingCost = $cost;
                }
                $this->log("{$user->info()} chose {$foundCard->getName()}");
                $this->cardsChosen[$user->id()] = $foundCard;
                $user->selectedCard = $foundCard;
                $user->send('canplay', '');
                // if everyone's played a card, execute cards and redeal/new turn
                if (count($this->cardsChosen) == count($this->players)) {
                    $this->playCards();
                }
                break;

            case 'cardignore':
                $user->isTrashing = false;
                unset($user->selectedCard);
                unset($user->pendingCost);
                unset($this->cardsChosen[$user->id()]);
                break;

            case 'checkresources':
                $cardName = $args['value'];
                foreach($user->hand as $card)
                    if($card->getName() == $cardName)
                        $toPlay = $card;
                if(!isset($toPlay))
                    return;

                $user->findCost($toPlay);
                break;

            default:
                echo "Undefined message\n";
                print_r($args);
                break;
        }
   	}
}

?>

