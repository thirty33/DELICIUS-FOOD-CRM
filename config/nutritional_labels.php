<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nutritional Label Logo URLs
    |--------------------------------------------------------------------------
    |
    | CloudFront signed URLs for nutritional label logos.
    | These URLs are valid for 365 days and are used in the PDF generation.
    |
    */

    'logos' => [
        // Main company logo
        'main_logo' => env(
            'LABEL_LOGO_MAIN',
            'https://pedidosdelicius.cl/public-assets/Negro_Peque%C3%B1o.png?Expires=1795733578&Signature=iuL-HAzbSrAIL3cSdR-u2TDjsXIeKO~6CLsRRsy6gMfIoOZH9VGNJEG~w6ADAntt0OwOgQZ9CaqA~6xi0GPBmIlAqzBVJwDR0dHLDI3ECQ5-ywgS8hoSzvBB6LudJDCwFk3jt3e2YtQsTNC~3c0qHT-JQ0y1K8O56uwsjWGH9u~sg-kOX~vZbjFhdFoICZ0Rw2bIw~So0EfdkQuM3wrObvJvOKNvKqZPtvDtT61etO65IUM39ZUbGKvwNikqaM1m4re5fyuKQLcarEJK5kIMaUHHcIzBX44WZNxSENhttriIkK2EcSpA8kKAL6v4Wi22Yum2BCwYKdkcdw-POyLOwg__&Key-Pair-Id=KQGAD8YX979YO'
        ),

        // Nutritional warning labels
        'alto_azucares' => env(
            'LABEL_LOGO_ALTO_AZUCARES',
            'https://pedidosdelicius.cl/public-assets/alto_azucares.png?Expires=1795733578&Signature=Bcyq7Rrjf38dmQbo~U4nrQh5TTZ1xjUNzKEZb31ZpKsaKewBKTkGwbFeauIm~jQbyQiU5SAXTZcBY96fD-mfOCf9z0pJquGv7mIgELn0NNvUuvDmxFdS~XnfDftBJSiPgFTp3UQjXWTtDffoT~LslAJn5JFhmCc7V5iwAVzxz4tJHIL-PiV0fLpeQ8rTHPxIriW8m5~xQ1pvJlrGmfZQ7YkOKKuGtJHZNNk2bNDs-IG96u3VljQR~USMGkzppPgIt9ehlLHvg2cnCeam0Tb5mFH1PHzE5OakkvAlu1ePytoniiT-TLB7PVsiQBw~8wGls6ZPJNOUaF3dQDFL4neEiQ__&Key-Pair-Id=KQGAD8YX979YO'
        ),

        'alto_calorias' => env(
            'LABEL_LOGO_ALTO_CALORIAS',
            'https://pedidosdelicius.cl/public-assets/alto_calorias.png?Expires=1795733578&Signature=VJIXt5bi2T0lsdGVrmR~~fS~tfpkDiHrvUlm4pIV-OLB9hDKT~nzfbyuk5vPU2h72R0I76nHcUrsg~6iclQgC8w--uTroKlq45ju-EofZSKT-Pnm1jzLSQqRTkjqUPKIItfuY9Yo5ZT7GqfcCXYYz0kGnus1fHDp4k3TZHViMrsUg09GAEm5KoDu2RUif2IMYdaHNBxw77Rwm1xFksBPr1NV76GVsD58pSprHXrRKW68bEqn9FnajPiYu6skUkicgqQYax-jos1WzKi3EpbpaJs85OKrzsOMGx3JCyDcF58SKwX0dMK6jLTKseJaUcusV5aafnLQDmxAeUN5MpKO3w__&Key-Pair-Id=KQGAD8YX979YO'
        ),

        'alto_sodio' => env(
            'LABEL_LOGO_ALTO_SODIO',
            'https://pedidosdelicius.cl/public-assets/alto_sodio.png?Expires=1795733579&Signature=GcWcZZFY752Xj9ulxyZgzVcnsKsSJt19ISRIMvuEZrdoaMIb9L5Zx2BGGc9sjIh4XWDW0KWPGIC1UgsnCVJVBXQpTEOIjSGEf5t3SM9m7FhLmbrKhvBzha0G2iZPXOG5JicSY-yMZck-l6lDs7Xkxjb1BuArF-1Rong-7GSeOMduHOsXzQBgPJBmCl-LFs0wdx3KMEFr--jX4qaixBe2k817zcQtSyoyfLWDKVMQgUzJJetMX0XAs2MJTID43Q6bwLe3GBNCsKVk1mWwoc~tIWRZzwaYFJr53jIFQs5AFbZsGRMQJmaxpBdqq2q8WZAAw05vVjH6WkUWYPK3iiSTWA__&Key-Pair-Id=KQGAD8YX979YO'
        ),

        'alto_grasas_saturadas' => env(
            'LABEL_LOGO_GRASAS_SATURADAS',
            'https://pedidosdelicius.cl/public-assets/grasas_saturadas.png?Expires=1795733579&Signature=aEwjGptQG7ivQnoJIF9DVvNQ7hGMtFFOC9hMJ0fspAvmqMHBBWF6MjDLcZqkSvmGw8sVzdCnHPCWM6Y-q1~Aj0l-AumOVhNuKOV2lZRFexve5ST~OVP7xDINlaCQT~psEpCYNsnQZd6fgXA~JvwzVhVxITXMf5Sq6Rkmm8deWgnbXkH6szgvspI4GP6Q4ZMGj9Yag0VgY0IafVB4Z46Ntos8pFkEeH3bCDrK1tLFKbAmBtO1Gg6KvRzC6aqfFwm4h5hx28SDS1Pw8GZCb2lC2obCyi73ZlLEt5BSssYTwklTZ0mkVwh8gORZvAqqLth6AS7IDo0IVikvWcM1x9Hwkw__&Key-Pair-Id=KQGAD8YX979YO'
        ),

        // Social media icons
        'arroba' => env(
            'LABEL_LOGO_ARROBA',
            'https://pedidosdelicius.cl/public-assets/arroba.png?Expires=1795733579&Signature=IS-16-ps7nf11wYsC7y9oXpwrdITqoM0h2-P2dcugYq4r9K~HNrMkiMzF2ULgG8qSX4fttVFI~x7UV0tJPf67rQ7Wt~liD6dqjpq~0bOPbxC7ywvJBYFGME9~Pv6Mf7pjf5VBQPjw0Qg9QXVgmZu1fs7PqtTm6NpraRGXoEJlHBSVqPhLrvbt9Y9vBthslNuJ8mqoaIPtxafBpPDuL3v1Y~RU-JSiuaapyNianrdKyGvd7amTTDoBEH9o1wCfelc7Eml1Bs7SES-C4pq8~FzYLN4uSTEgRmmidbFjDB4jSmaroMn0efmJlqx7jdQ3oXp6uba3KVNmAHlQUdH54y-dQ__&Key-Pair-Id=KQGAD8YX979YO'
        ),

        'whatsapp' => env(
            'LABEL_LOGO_WHATSAPP',
            'https://pedidosdelicius.cl/public-assets/whatsapp.png?Expires=1795733579&Signature=hPf10k~-XJ7OWFpiaC1ZtFdHbMVQ8V-Oe9OfpYHH8Nb12xhGPUS~jaw1Yaf-i6jrrHV91LjIaPPREn0g2ZL3lq-JtgduGS7kWUmtS6m2QkS8LIi-tlQnYs-uvuPe9iIDncw-pJTNk-kasisYM08asy5cGBTwmE1DCYbWwT8tveV51cVhjt29k3Rzlvm8XLa5xsANGYixaae5-n5v5-fZFzaQrZmJlmLZQoNuLeUruaP8mzd-g~kZbkvgmb6BkMaV-Hqr9itmpj-PgDL2hoBxJWdEGjgSNtgt-uRW-7VFF76XxcZHIPQj-Kd6yrwh82Z56xmjVuv1KxlIfBm9hTGm2Q__&Key-Pair-Id=KQGAD8YX979YO'
        ),

        'instagram' => env(
            'LABEL_LOGO_INSTAGRAM',
            'https://pedidosdelicius.cl/public-assets/instagram.png?Expires=1795733579&Signature=SsUMMGJh8RW4JtKCWLroiXfAnG8LbNxjHNCog1LKia32ZLVYkd~wP0P7BXOH135hZ3lnycDr3S3IhLbD9jIUHj6tjQ~wpqoFwZ32Dp1wfyw0uq3j2tOvLRYq5yQK1P3PUaUm5C8jzED7KIE1m6bQLjhj4yCQ40yGSOnR9mWzJVna1NVj-JraayXXTLJL75WFTlYo-GwoW71a5f5mOlnnuaY1mdblU1x0DLk9hYm6~gEYj2tx-fzjqFK8f9h38KhGpd71VstQ6ggaBlED1rK6u2c2qavDMNsIyAkQW0floxY623G40zwAN~RNtuy6bvBw~Q6nA9kE5mLien1K7tmBdA__&Key-Pair-Id=KQGAD8YX979YO'
        ),

        'worldwide' => env(
            'LABEL_LOGO_WORLDWIDE',
            'https://pedidosdelicius.cl/public-assets/worldwide.png?Expires=1795733580&Signature=MlcceK7eRgn7Vj-zgm6lqrwbPI8HjiNG3WkYe9Pv3krnD-Dqcn2KkOuIi9-yti3k3jYkQRPQps-DiDDSos6nSVtsDKC6GjphEbTFRx4JieqrjKN4z1f7ZVXoXzojvr-8t1eykPGS6sbUavuF4n8bMrgq9aaaRYiXUCP3pbLPkEfUSL0Xn2NXbRgxDHK8-law8JkyqCoT6TBuj9v4y-gxIibWKHeNYmIF8-u8q-OPtENzSoz7BQVvNBCxYFDSJfcnBibQwMQEaphKa5aWbMKw2OkowCG7aT1Y-6-rMh2tLuVOsevcEQwLzhDZN8rbPQg0i7cMHw5EJgn5yFkjmQ8qoQ__&Key-Pair-Id=KQGAD8YX979YO'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alternative Logo Names
    |--------------------------------------------------------------------------
    |
    | Some logos have alternative naming conventions (altoenzucares vs alto_azucares).
    | These aliases map alternative names to the canonical logo keys.
    |
    */

    'logo_aliases' => [
        'altoenzucares' => 'alto_azucares',
        'altoencalorias' => 'alto_calorias',
        'altoensodio' => 'alto_sodio',
    ],

];