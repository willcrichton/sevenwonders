This is a web port of the board game Seven Wonders. I do not own Seven Wonders
or its trademark, nor do I own any of the game's assets. Seven Wonders Online is
a purely educational endeavour to have fun while programming.

# Running the code

If you have php 5.4 installed, all you need to do is:

```
php -S localhost:9000
php server.php
```

And then visit `localhost:9000` in a browser. If you're using apache, then you
don't need the `php -S` command, just be sure to point apache at the root of the
repository.

TODO
* Highlight free cards (or otherwise display _actual_ resource costs), grey out unplayable cards
* Make slider for play cost more clear/more intuitive
* What does player highlight sidebar do?
* Give undo button on play cost screen
* Show current victory points count (?)
* Highlight winner on point screen
* Back button on the point screen
* Top/bottom margins on Safari
* Font centering on guest tiles at top of board
* Don't display resources/wonders for other players in pick phase
* Splash screen/text/etc. on when we change ages