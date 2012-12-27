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
    public $wonders;
    public $wondersChosen = false;
    public $playerInfo;
    public $discard = array();

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
                        Card::csvResources($value['resource'], true)[0];

                foreach ($value['stages'] as &$stage) {
                    if (isset($stage['resource']))
                        $stage['resource'] =
                            Card::csvResources($stage['resource'], false)[0];
                    $stage['requirements'] =
                        Card::csvResources($stage['requirements'], false);
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

    private function playCards($isDiscard = false) {
        $this->log("All cards found");

        // execute effects of all played cards
        foreach ($this->players as $player) {
            $data = array();
            if ($player->leftPlayer->isPlayingCard())
                $data['left'] = $player->leftPlayer->selectedCard->json();
            if ($player->rightPlayer->isPlayingCard())
                $data['right'] = $player->rightPlayer->selectedCard->json();
            $player->send('cardschosen', $data);

            if($isDiscard and (!isset($player->state) or $player->state != Player::USINGDISCARD)) 
                continue;
            // play both cards (if we can)
            $player->playCard($this, $player->selectedCard, $player->state,
                              $player->pendingCost);
            if (isset($player->secondState))
                $player->playCard($this, $player->secondPending,
                                  $player->secondState, $player->secondCost);
        }

        $shouldPause = false;
        foreach ($this->players as $player) {
            if(!isset($player->state) or $player->state != Player::USINGDISCARD or $isDiscard)
                unset($player->state);
            else
                $shouldPause = true;
            unset($player->selectedCard);
            unset($player->pendingCost);
            unset($player->secondState);
            unset($player->secondPending);
            unset($player->secondCost);

            if ($this->turn == 6 && count($player->hand) > 0)
                $this->discard[] = array_pop($player->hand);
        }

        if(!$shouldPause){
             if ($this->turn == 6) {
                // go into a new age
                $this->log("Ending age {$this->age}");

                $this->log("Evaluating military");
                foreach ($this->players as $player) {
                    $player->evaluateMilitary($this->age);

                    if ($player->canHaveFreeCard)
                        $player->getFreeCard();
                }

                $this->age++;
                $this->turn = 1;

                if($this->age == 4){
                    $this->endGame();
                } else {
                    $this->deal();
                }
            } else {
                // change hands and start a new turn
                $this->log("Ending turn " . $this->turn);
                $this->turn++;
                $this->rotateHands($this->age != 2);
            }
        } else {
            foreach($this->players as $player)
                $player->send('hand', array('card' => array()));
        }

        foreach($this->players as $player){
            $points = array_sum($player->calcPoints());
            $this->log("{$player->info()} has $points points");
        }
    }

    public function onMessage(Player $user, $args){
        switch($args['messageType']){
            case 'cardignore':
                $card = $args['card'];
                    // First handle if you ignore the selected card (default)
                if (isset($user->state) && isset($user->selectedCard) &&
                    $user->selectedCard->getName() == $card) {
                    unset($user->state);
                    unset($user->selectedCard);
                    unset($user->pendingCost);
                }

                // If you ignored the first card, then we have to force ignoring
                // the second card (maybe first enabled second). Otherwise we
                // only need to ignore the second card if it's the actual card
                if (isset($user->secondState) &&
                    ($user->secondPending->getName() == $card ||
                     !isset($user->state))) {
                    unset($user->secondState);
                    unset($user->secondPending);
                    unset($user->secondCost);
                }
                break;

            case 'cardplay':
                // TODO: this means that a refreshed game with a selected card
                // won't be able to play any new cards because the card
                // selection isn't pushed back to the user in the game info sent
                if(isset($user->state) and $user->state == Player::USINGDISCARD){
                    foreach($this->discard as $card)
                        if($card->getName() == $args['value'][0])
                            $foundCard = $card;
                    if(!isset($foundCard)) break;
                    $user->selectedCard = $foundCard;
                    $user->pendingCost = array();
                    $this->playCards(true);
                    break;
                }

                if (isset($user->state) &&
                    ($this->turn != 6 ||
                     !$user->canPlayTwo() ||
                     isset($user->secondState)))
                    break;

                $cardName = $args['value'][0];
                // match what they sent with a Card object
                foreach ($user->hand as $card)
                    if($card->getName() == $cardName)
                        $foundCard = $card;
                if (!isset($foundCard)) // don't have the specified card
                    break;

                $cost = array();
                $state = '';
                if ($args['value'][1] == 'trash') {
                    $state = Player::TRASHING;
                } else if ($args['value'][1] == 'free') {
                    if (!$user->hasFreeCard)
                        break;
                    $state = Player::USINGFREE;
                } else {
                    $cost = $user->cardCost($foundCard, $args['value'][2],
                                            $args['value'][1]);
                    if ($cost === false)
                        break;
                    if ($args['value'][1] == 'wonder')
                        $state = Player::BUILDING;
                    else
                        $state = Player::BUYING;
                }
                $this->log("{$user->info()} chose {$foundCard->getName()} in state $state");

                if (!isset($user->state)) {
                    $user->state = $state;
                    $user->selectedCard = $foundCard;
                    $user->pendingCost = $cost;
                } else {
                    $user->secondState = $state;
                    $user->secondPending = $foundCard;
                    $user->secondCost = $cost;
                }
                // if everyone's played a card, execute cards and redeal/new turn
                if ($this->finishedChoosing())
                    $this->playCards();
                break;

            case 'checkresources':
                $cardName = $args['value'];
                foreach($user->hand as $card)
                    if($card->getName() == $cardName)
                        $toPlay = $card;
                if(!isset($toPlay))
                    return;

                $user->findCost($toPlay, $args['type']);
                break;


            case 'wonderside':
                if($this->wondersChosen) break;
                $side = $args['value'] == true ? 'a' : 'b';
                $user->wonderSide = $side;
                foreach($this->wonders as $wonder){
                    if($wonder['name'] == $user->wonderName){
                        $user->wonder = $wonder[$side];
                        if (isset($user->wonder['resource']))
                            $user->addResource($user->wonder['resource']);
                        break;
                    }
                }

                $allChosen = true;
                foreach($this->players as $player){
                    if(!isset($player->wonder)){
                        $allChosen = false;
                        break;
                    }
                }

                if($allChosen){
                    $this->log("All wonders chosen, dealing hands");
                    $this->wondersChosen = true; //todo: incorporate this into some sort of state var
                    foreach($this->players as $player){
                        $player->send('neighborresources', array(
                            'left' => $player->leftPlayer->wonder['resource']->json(),
                            'right' => $player->rightPlayer->wonder['resource']->json()
                        ));
                    }
                    $this->deal();
                }
                break;

            case 'playerinfo':
                $token = $args['value'];
                foreach($this->players as $player){
                    if($player->id() == $token){
                        $user->send('playerinfo', $player->getPublicInfo());
                        break;
                    }
                }
                break;

            default:
                echo "Undefined message\n";
                print_r($args);
                break;
        }
    }

    private function finishedChoosing() {
        $count = 0;
        foreach ($this->players as $player) {
            if (!isset($player->state))
                continue;
            if ($this->turn == 6 && $player->canPlayTwo() &&
                !isset($player->secondState))
                continue;
            $count++;
        }
        return $count == count($this->players);
    }

    private function endGame(){
        $scores = array();
        foreach($this->players as $player){
            $scores[$player->id()] = $player->calcPoints();
        }
        $this->server->broadcast('scores', $scores);
    }
}

?>
