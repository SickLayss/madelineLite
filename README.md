# MadelineLite

This is a barebone version of [MadelineProto](https://docs.madelineproto.xyz).


It has no update handling, no peer handling, no flood waits, no secret chat handling, no call handling.

It is **very** fast.


The only wrapper methods that I left are a very basic and fast version of get_updates that returns Updates instead of Update objects, and file upload/download methods.

Since the login wrapper methods were removed, too, the only way to use madelineton is to load a full MadelineProto session.

Don't worry, everything useless will be removed.


Have fun.


Daniil Gentili
