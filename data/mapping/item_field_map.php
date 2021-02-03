<?php
// Warning: the mapping is not one-to-one, so some data may be lost when the
// mapping is reverted. You may adapt it to your needs.

return [
    'url'               => 'bibo:uri',
    'desc'              => 'dcterms:description',
    'titre'             => 'dcterms:title',
    'title'             => 'dcterms:title',
    'dateCreated'        => 'dcterms:created',
    'dateModified'        => 'dcterms:dateSubmitted',
    'author'              => 'dcterms:creator',
    'id'                 => 'dcterms:isReferencedBy',
    'type'         => 'dcterms:type',
    'body'         => 'bibo:content',
    'source'         => 'dcterms:source',
    'isPartOf'         => 'dcterms:isPartOf',
];
