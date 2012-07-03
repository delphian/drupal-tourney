<?php

/**
 * @file
 * Double elimination controller, new system.
 */

/**
 * A class defining how matches are created, and rendered for this style
 * tournament.
 */
class SpecialDoubleEliminationController extends SingleEliminationController {
  /**
   * Theme implementations specific to this plugin.
   */
  public static function theme($existing, $type, $theme, $path) {
    $parent_info = TourneyController::getPluginInfo(get_parent_class($this));
    return parent::theme($existing, $type, $theme, $parent_info['path']);
  }
  
  public function buildBrackets() {
    parent::buildBrackets();
    $this->data['brackets']['loser'] = $this->buildBracket(array('id' => 'loser'));
    $this->data['brackets']['champion'] = $this->buildBracket(array('id' => 'champion'));
  }
  
  public function buildMatches() {
    parent::buildMatches();
    $this->buildBottomMatches();
    $this->buildChampionMatches();
  }
  
  public function buildBottomMatches() {
    $match = &drupal_static('match', 0);
    $round = &drupal_static('round', 0);
    
    // Rounds is a certain number, 2, 4, 6, based on the contestants
    $num_rounds = (log($this->slots, 2) - 1) * 2;
    foreach (range(1, $num_rounds) as $round_num) {
      $this->data['rounds'][++$round] = 
        $this->buildRound(array('id' => $round_num, 'bracket' => 'loser'));
        
      // Bring the round number down to a unique number per group of two
      $round_group = ceil($round_num / 2);
      
      // Matches is a certain number based on the round number and slots
      // The pattern is powers of two, counting down: 8 8 4 4 2 2 1 1
      $num_matches = $this->slots / pow(2, $round_group + 1);
      foreach (range(1, $num_matches) as $roundMatch) {
        $this->data['matches'][++$match] = 
          $this->buildMatch(array(
            'id' => $match,
            'round' => $round_num,
            'roundMatch' => (int) $roundMatch,
            'bracket' => 'loser',
        ));
      }
    }
  }
  
  public function buildChampionMatches() {
    $match = &drupal_static('match', 0);
    $round = &drupal_static('round', 0);
    
    foreach (array(1, 2) as $round_num) {
      $this->data['rounds'][++$round] = 
        $this->buildRound(array('id' => $round_num, 'bracket' => 'champion'));
      
      $this->data['matches'][++$match] = 
        $this->buildMatch(array(
          'id' => $match,
          'round' => $round_num,
          'roundMatch' => 1,
          'bracket' => 'champion',
      ));
    }
  }
  
  /**
   * Find and populate next/previous match pathing on the matches data array for
   * each match.
   */
  public function populatePositions() {
    parent::populatePositions();
    $this->populateLoserPositions();
  }
  
  public function populateLoserPositions() {
    // Go through all the matches
    $count = count($this->data['matches']);
    foreach ($this->data['matches'] as $id => &$match) {
      // Set the paths for the main bracket
      if ($match['bracket'] == 'main') {
        // Calculate all the next loser positions in the top bracket.
        $next = $this->calculateNextPosition($id, 'loser');
        $match['nextMatch']['loser'] = $next;
        
        // Set the winner path for the last match of the main bracket
        $top_matches = $this->slots - 1;
        if ($top_matches == $id) {
          $match['nextMatch']['winner'] = count($this->data['matches']) - 1;
        }
      }
      elseif ($match['bracket'] == 'loser') {
        // Calculate all the next loser positions in the bottom bracket.
        $next = $this->calculateNextPosition($id, 'winner');
        $match['nextMatch']['winner'] = $next;
      }
      elseif ($match['bracket'] == 'champion') {
        $last_match = count($this->data['matches']);
        if ($last_match - 1 == $id) {
          $match['nextMatch']['winner'] = $last_match;
          $match['nextMatch']['loser'] = $last_match;
        }
      }
    }
  }
  
  public function render() {
    return parent::render();
  }
  
  /**
    * Given a match place integer, returns the next match place based on
    * either 'winner' or 'loser' direction
    *
    * @param $place
    *   Match placement, one-based. round 1 match 1's match placement is 1
    * @param $direction
    *   Either 'winner' or 'loser'
    * @return $place
    *   Match placement of the desired match, otherwise NULL
    */
  protected function calculateNextPosition($place, $direction = "loser") {
    // @todo find a better way to count matches
    $slots = $this->slots;
    // Set up our handy values
    $matches = $slots * 2 - 1;
    $top_matches = $slots - 1;
    $bottom_matches = $top_matches - 1;

    // Champion Bracket
    if ($place >= $matches - 2) {
      // Last match goes nowhere
      if ($place == $matches - 1) {
        return NULL;
      }
      return $place + 1;
    }
    
    if ($direction == 'winner') {
     // Top Bracket
     if ($place < $top_matches) {
       // Last match in the top bracket goes to the champion bracket
       if ($place == $top_matches) {
         return $matches - 1;
       }
       return parent::calculateNextPosition($place);
     }
     // Bottom Bracket
     else {
       // Get out series to find out how to adjust our place
       $series = $this->magicSeries($bottom_matches);
       return $place + $series[$place - $top_matches];
     }
    }
    elseif ($direction == 'loser') {
      // Top Bracket
      if ($place <= $top_matches) {
        // If we're calculating next position for a match that is coming from 
        // the first round in the top bracket, that math is simply to find the
        // the next match winner position and then add half the number of
        // bottom matches to that position, to find the bottom loser position.
        if ($this->data['matches'][$place]['round'] == 1) {
          return parent::calculateNextPosition($place) + ($bottom_matches / 2);
        }
        
        // Otherwise, more magical math to determine placement. Every even round
        // in the top bracket we need to flip the matches so that the top half of 
        // matches go to the bottom as such:
        //
        // 1, 2, 3, 4, 5, 6, 7, 8
        //          \/
        // 5, 6, 7, 8, 1, 2, 3, 4
        //
        // and on the special occasions with byes, it can go:
        //
        // 6, 5, 8, 7, 2, 1, 4, 3
        //
        
        // Figure out if we need to reverse the round, set a boolean flag if
        // we're in an even round.
        $reverse_round = $this->data['matches'][$place]['round'] % 2 ? FALSE : TRUE;
        
        // Get the number of matches in this round
        $match_info = $this->find('matches', array('round' => $this->data['matches'][$place]['round']));
        $round_matches = count($match_info);
        $half = $round_matches / 2;
        if ($reverse_round) {
          // Since this round is a reverse round we need to put the first half 
          // of matches in the second half of the round
          if ($this->data['matches'][$place]['roundMatch'] <= $half) {
            $adj = $half;
          }
          // And then move what is supposed to be in the second half to the 
          // first half of the round.
          else {
            $adj = -$half;
          }
        }
        // Non reverse rounds just need to cut by half the number of matches in 
        // the round plus 1 (@todo: figure out why this works and document it.).
        else {
          $adj = floor(-1 * ($half / 2)) + 1;
        }
        // Last match in top bracket adjust forward by one.
        // @todo: Another adjustment that I made that works, need to figure out
        //   why and document it.
        if ($place == $top_matches) {
          $adj = -1;
        }
        return $place + $top_matches - $round_matches + $adj;
      }
    }
    return NULL;
  }
  
  /**
   * This is a special function that I could have just stored as a fixed array,
   * but I wanted it to scale. It creates a special series of numbers that
   * affect where loser bracket matches go
   *
   * @param $until
   *   @todo I should change this to /2 to begin with, but for now it's the
   *   full number of bottom matches
   * @return $series
   *   Array of numbers
   */
  private function magicSeries($until) {
    $series = array();
    $i = 0;
    // We're working to 8 if until is 16, 4 if until is 8
    while ($i < $until / 2) {
      // Add in this next double entry of numbers
      $series[] = ++$i;
      $series[] = $i;
      // If it's a power of two, throw in that many numbers extra
      if (($i & ($i - 1)) == 0) {
        foreach (range(1, $i) as $n) {
          $series[] = $i;
        }
      }
    }
    // Remove the unnecessary last element in the series (which is the start
    // of the next iteration)
    while (count($series) > $until) {
      array_pop($series);
    }
    
    // Reverse it so we work down, and make the array 1-based
    return array_combine(range(1, count($series)), array_reverse($series));
  }
}
