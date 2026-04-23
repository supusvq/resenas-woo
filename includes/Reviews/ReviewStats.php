<?php
namespace MRG\Reviews;

if (!defined('ABSPATH')) {
    exit;
}

class ReviewStats {
    private $repo;

    public function __construct(ReviewRepository $repo) {
        $this->repo = $repo;
    }

    public function count_all() {
        return $this->repo->count_all();
    }

    public function average_rating() {
        return $this->repo->average_rating();
    }
}
