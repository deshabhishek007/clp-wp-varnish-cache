<?php

enum PurgeType: string {
    case Host    = 'host';
    case Tag     = 'tag';
    case Url     = 'url';
    case HostTag = 'host+tag';
}
