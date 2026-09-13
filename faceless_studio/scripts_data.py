"""
Banco de guiones para el canal de curiosidades/historias.

Cada entrada trae todo lo necesario para generar un short:
- id: identificador único (nombre de carpeta de salida)
- title: usado en la miniatura
- hook: primera frase, pensada para retener en los primeros 2 segundos
- script: texto completo que se narra (hook incluido)
- description / tags: metadata lista para copiar en YouTube Studio

Los datos son verificables y están redactados para no caer en clickbait
falso (evita quejas por desinformación y desmonetización). Para escalar el
canal más allá de estos 12, sumá entradas nuevas a esta lista siguiendo el
mismo formato, o generalas con un LLM (ver README, sección "Escalar
contenido").
"""

SCRIPTS = [
    {
        "id": "01_pulpo_corazones",
        "title": "Los pulpos tienen 3 corazones",
        "hook": "Los pulpos tienen tres corazones, y uno de ellos deja de latir cuando nadan.",
        "script": (
            "Los pulpos tienen tres corazones, y uno de ellos deja de latir cuando nadan. "
            "Dos corazones bombean sangre hacia las branquias, y un tercero la envía al resto "
            "del cuerpo. Pero ese tercer corazón se detiene cada vez que el pulpo nada rápido. "
            "Por eso prefieren arrastrarse por el fondo del mar: nadar los agota muchísimo más "
            "rápido. Además, su sangre es azul, porque en lugar de hierro usa cobre para "
            "transportar oxígeno. Y no termina ahí: si un pulpo pierde un brazo, ese brazo "
            "puede seguir moviéndose solo durante varias horas, porque tiene su propio grupo de "
            "neuronas. De hecho, dos tercios de las neuronas de un pulpo están en sus brazos, "
            "no en su cerebro."
        ),
        "description": (
            "Los pulpos esconden datos que parecen ciencia ficción: tres corazones, sangre azul "
            "y brazos con cerebro propio. #curiosidades #datoscuriosos #pulpo #animales #ciencia"
        ),
        "tags": ["curiosidades", "datos curiosos", "pulpo", "animales", "ciencia", "naturaleza"],
    },
    {
        "id": "02_gran_muralla_espacio",
        "title": "La Gran Muralla NO se ve desde el espacio",
        "hook": "La Gran Muralla China no se ve a simple vista desde el espacio, y hay un astronauta que lo confirmó.",
        "script": (
            "La Gran Muralla China no se ve a simple vista desde el espacio. Es uno de los mitos "
            "más repetidos de la historia, pero el astronauta chino Yang Liwei lo desmintió "
            "personalmente después de su misión en 2003: dijo que no pudo distinguirla en "
            "ningún momento. El problema es simple: la muralla mide entre cuatro y ocho metros "
            "de ancho, un tamaño ridículo comparado con la distancia de cientos de kilómetros "
            "hasta la órbita baja. Desde ahí arriba, en cambio, sí se pueden ver cosas como "
            "ciudades iluminadas de noche, o los invernaderos gigantes del sur de España, que "
            "reflejan tanta luz solar que parecen un mar blanco. El mito de la muralla "
            "probablemente nació mucho antes de que existieran los vuelos espaciales, en el "
            "siglo diecinueve, y simplemente nunca se corrigió."
        ),
        "description": (
            "Uno de los mitos más repetidos del mundo, desmentido por quien mejor podía hacerlo: "
            "un astronauta. #curiosidades #datoscuriosos #historia #espacio #mitos"
        ),
        "tags": ["curiosidades", "datos curiosos", "historia", "espacio", "mitos", "china"],
    },
    {
        "id": "03_miel_eterna",
        "title": "La miel nunca se pudre",
        "hook": "Encontraron miel de tres mil años de antigüedad en tumbas egipcias, todavía comestible.",
        "script": (
            "Encontraron miel de tres mil años de antigüedad en tumbas egipcias, y seguía siendo "
            "comestible. La miel es uno de los pocos alimentos que prácticamente no caduca. El "
            "secreto está en su química: tiene muy poca agua y es muy ácida, un ambiente donde "
            "las bacterias y los hongos no pueden sobrevivir. Además, las abejas le agregan una "
            "enzima que genera pequeñas cantidades de peróxido de hidrógeno, el mismo compuesto "
            "que se usa como desinfectante. Eso sí, si la miel se cristaliza o se pone turbia no "
            "significa que esté mala: simplemente hay que calentarla un poco a baño maría y "
            "vuelve a su textura líquida. Lo único que puede arruinarla de verdad es que le "
            "entre humedad y empiece a fermentar."
        ),
        "description": (
            "La miel es prácticamente inmortal: hay frascos de tres mil años que todavía se "
            "podrían comer. #curiosidades #datoscuriosos #historia #egipto #ciencia"
        ),
        "tags": ["curiosidades", "datos curiosos", "miel", "egipto", "ciencia", "historia"],
    },
    {
        "id": "04_torre_eiffel_crece",
        "title": "La Torre Eiffel crece en verano",
        "hook": "La Torre Eiffel puede crecer hasta 15 centímetros en verano, solo por el calor.",
        "script": (
            "La Torre Eiffel puede crecer hasta quince centímetros en verano, solo por el calor. "
            "Está construida casi por completo en hierro forjado, un metal que se dilata cuando "
            "sube la temperatura. En los días más calurosos de París, el metal se expande y la "
            "torre gana esos centímetros extra, para luego encogerse otra vez cuando llega el "
            "frío. Ese mismo fenómeno hace que la torre se incline levemente hacia el lado "
            "contrario al sol, porque el metal expuesto a la luz se dilata más rápido que el "
            "lado que queda en sombra. Gustave Eiffel diseñó la estructura pensando en esto: "
            "dejó espacios de holgura entre las piezas de hierro para que todo ese movimiento no "
            "generara grietas ni tensiones peligrosas."
        ),
        "description": (
            "La Torre Eiffel se mueve, crece y se inclina todos los días por el calor. "
            "#curiosidades #datoscuriosos #paris #arquitectura #ciencia"
        ),
        "tags": ["curiosidades", "datos curiosos", "torre eiffel", "paris", "arquitectura", "ciencia"],
    },
    {
        "id": "05_banano_radioactivo",
        "title": "Las bananas son un poco radiactivas",
        "hook": "Las bananas son ligeramente radiactivas, y hasta existe una unidad de medida basada en eso.",
        "script": (
            "Las bananas son ligeramente radiactivas, y hasta existe una unidad de medida basada "
            "en eso. Contienen potasio-cuarenta, un isótopo natural del potasio que emite una "
            "radiación mínima. Es tan poca que los científicos inventaron en broma la 'dosis "
            "equivalente de banana', para explicarle al público qué tan chica es una cantidad de "
            "radiación. Comer una banana equivale a una fracción diminuta de la radiación que "
            "recibís en un vuelo de avión, o incluso menos que dormir al lado de otra persona, "
            "porque los cuerpos humanos también emiten algo de radiación natural. No hay ningún "
            "riesgo real: el cuerpo regula el potasio y expulsa el excedente todo el tiempo, así "
            "que ninguna cantidad de bananas te va a hacer brillar en la oscuridad."
        ),
        "description": (
            "Sí, las bananas son radiactivas, pero la cantidad es tan chiquita que hasta le "
            "pusieron nombre en broma. #curiosidades #datoscuriosos #ciencia #fisica #bananas"
        ),
        "tags": ["curiosidades", "datos curiosos", "ciencia", "fisica", "radiacion", "bananas"],
    },
    {
        "id": "06_napoleon_altura",
        "title": "Napoleón no era bajito",
        "hook": "Napoleón no era bajo, medía un metro setenta, la altura promedio de un hombre francés de su época.",
        "script": (
            "Napoleón no era bajo. Medía alrededor de un metro setenta, prácticamente la altura "
            "promedio de un hombre francés de su época. El mito nació por un error de "
            "conversión: los franceses usaban una unidad llamada 'pulgada francesa', más larga "
            "que la pulgada inglesa, y cuando los británicos tradujeron mal su altura, "
            "publicaron un número mucho menor al real. La propaganda británica, que lo "
            "presentaba como un caricatura, se encargó de repetir esa cifra hasta que se volvió "
            "verdad popular. Además, Napoleón solía estar rodeado de su guardia imperial, "
            "soldados seleccionados por ser especialmente altos, lo que hacía que a su lado "
            "pareciera más bajo de lo que en realidad era."
        ),
        "description": (
            "El mito de 'Napoleón bajito' nació de un error de conversión y una campaña de "
            "propaganda. #curiosidades #datoscuriosos #historia #napoleon #mitos"
        ),
        "tags": ["curiosidades", "datos curiosos", "historia", "napoleon", "mitos", "francia"],
    },
    {
        "id": "07_vikingos_cuernos",
        "title": "Los vikingos no usaban cascos con cuernos",
        "hook": "Los vikingos jamás usaron cascos con cuernos, esa imagen se inventó siglos después.",
        "script": (
            "Los vikingos jamás usaron cascos con cuernos. No existe un solo casco vikingo "
            "auténtico, de los que se conservan en museos, que tenga cuernos. La imagen se "
            "inventó en el siglo diecinueve, cuando artistas escandinavos y alemanes empezaron a "
            "dibujar vikingos así para dar una imagen más dramática, inspirados en piezas de "
            "ópera y en cascos ceremoniales mucho más antiguos, de la Edad de Bronce, que no "
            "tenían nada que ver con los vikingos. En combate real, unos cuernos en la cabeza "
            "hubieran sido un estorbo enorme: el enemigo podría agarrarlos para desestabilizar "
            "al guerrero. Los cascos vikingos reales eran simples, de hierro o cuero reforzado, "
            "pensados para proteger, no para impresionar."
        ),
        "description": (
            "La imagen del vikingo con casco de cuernos es puro invento del siglo diecinueve. "
            "#curiosidades #datoscuriosos #historia #vikingos #mitos"
        ),
        "tags": ["curiosidades", "datos curiosos", "historia", "vikingos", "mitos"],
    },
    {
        "id": "08_corazon_ballena",
        "title": "El corazón de la ballena azul pesa como un auto",
        "hook": "El corazón de una ballena azul puede pesar tanto como un auto pequeño.",
        "script": (
            "El corazón de una ballena azul puede pesar tanto como un auto pequeño, alrededor de "
            "ciento ochenta kilos. Es tan grande que un ser humano adulto podría, literalmente, "
            "gatear por dentro de sus arterias principales. Cada latido bombea miles de litros "
            "de sangre para mantener con vida al animal más grande que existió jamás en la "
            "Tierra, más grande incluso que cualquier dinosaurio conocido. Y sin embargo, ese "
            "corazón gigante late muy despacio, apenas unas pocas veces por minuto, para ahorrar "
            "energía durante las largas inmersiones. Los científicos que lograron grabar el "
            "latido de una ballena azul en el mar notaron que el corazón casi se detiene por "
            "completo en la parte más profunda del buceo, y después se acelera de golpe al "
            "subir a la superficie a respirar."
        ),
        "description": (
            "El corazón del animal más grande de la Tierra pesa como un auto y late como en "
            "cámara lenta. #curiosidades #datoscuriosos #ballenas #animales #ciencia"
        ),
        "tags": ["curiosidades", "datos curiosos", "ballenas", "animales", "ciencia", "oceano"],
    },
    {
        "id": "09_reloj_atomico",
        "title": "Por qué el año tiene segundos extra",
        "hook": "Cada tanto, los relojes del mundo agregan un segundo extra para no desincronizarse con la Tierra.",
        "script": (
            "Cada tanto, los relojes del mundo agregan un segundo extra para no desincronizarse "
            "con la Tierra. Se llama 'segundo intercalar'. El problema es que la Tierra no gira "
            "a una velocidad perfectamente constante: mareas, terremotos e incluso el "
            "derretimiento de glaciares cambian levemente su rotación. Mientras tanto, los "
            "relojes atómicos, que definen la hora oficial, son tan precisos que no perdonan ni "
            "una fracción de milisegundo. Para que ambos sistemas no se separen con el tiempo, "
            "un organismo internacional decide cuándo sumar ese segundo extra, generalmente en "
            "juntas del treinta de junio o el treinta y uno de diciembre. Suena inofensivo, pero "
            "en el pasado ya causó errores en sistemas informáticos de grandes empresas, "
            "programados sin contemplar que un minuto pudiera tener sesenta y un segundos."
        ),
        "description": (
            "La Tierra no gira siempre igual, y por eso cada tanto el reloj del mundo entero se "
            "ajusta un segundo. #curiosidades #datoscuriosos #ciencia #tiempo #astronomia"
        ),
        "tags": ["curiosidades", "datos curiosos", "ciencia", "tiempo", "astronomia"],
    },
    {
        "id": "10_saturno_flota",
        "title": "Saturno flotaría en el agua",
        "hook": "Saturno es tan poco denso que flotaría si existiera una piscina lo bastante grande.",
        "script": (
            "Saturno es tan poco denso que flotaría si existiera una piscina lo bastante grande "
            "para meterlo. Su densidad promedio es menor a la del agua, algo único entre todos "
            "los planetas del sistema solar. Esto pasa porque Saturno está compuesto casi por "
            "completo de hidrógeno y helio, los dos elementos más livianos que existen, con muy "
            "poco material sólido en su interior. Por supuesto, en la práctica es imposible: "
            "ninguna piscina podría contener un planeta con un diámetro nueve veces mayor al de "
            "la Tierra, y además el agua se evaporaría por la enorme masa y temperatura "
            "involucradas. Pero como ejercicio mental, sirve para entender algo real: Saturno no "
            "es una bola sólida como la Tierra, sino más parecido a una gigantesca bola de gas "
            "con un núcleo pequeño y muy caliente en el centro."
        ),
        "description": (
            "Saturno es el único planeta del sistema solar menos denso que el agua. "
            "#curiosidades #datoscuriosos #espacio #astronomia #saturno"
        ),
        "tags": ["curiosidades", "datos curiosos", "espacio", "astronomia", "saturno", "sistema solar"],
    },
    {
        "id": "11_chicle_milenario",
        "title": "Masticar chicle es una costumbre de hace 9000 años",
        "hook": "Hace nueve mil años, la gente ya masticaba chicle, hecho con resina de abedul.",
        "script": (
            "Hace nueve mil años, la gente ya masticaba chicle. Arqueólogos encontraron en el "
            "norte de Europa pedazos de resina de corteza de abedul con marcas de dientes "
            "humanos, y hasta lograron extraer ADN de esa saliva antigua para saber quién los "
            "había masticado: en un caso, una adolescente. La resina se calentaba hasta "
            "ablandarse y se masticaba probablemente por varias razones: para calmar el dolor de "
            "muelas, porque tiene propiedades antisépticas leves, para limpiarse los dientes, o "
            "simplemente por entretenimiento, igual que hoy. El chicle moderno, hecho con base "
            "sintética o de látex de árboles tropicales como el chicozapote, recién apareció en "
            "el siglo diecinueve, pero la costumbre de masticar algo elástico viene, literalmente, "
            "desde la Edad de Piedra."
        ),
        "description": (
            "Masticar chicle no es un invento moderno: la gente ya lo hacía hace nueve mil años. "
            "#curiosidades #datoscuriosos #historia #arqueologia #ciencia"
        ),
        "tags": ["curiosidades", "datos curiosos", "historia", "arqueologia", "ciencia"],
    },
    {
        "id": "12_wifi_lento_pinguinos",
        "title": "Los pingüinos frenaron el wifi de una base científica",
        "hook": "En una base científica de la Antártida, los pingüinos lograron frenar la señal de wifi.",
        "script": (
            "En una base científica de la Antártida, los pingüinos lograron frenar la señal de "
            "wifi. Investigadores australianos que estudiaban pingüinos Adelia les colocaron "
            "pequeños sensores con transmisores de corto alcance para seguir sus movimientos sin "
            "molestarlos. El problema fue que esos transmisores usaban una frecuencia muy "
            "parecida a la del wifi de la base, y cuando cientos de pingüinos con sensores se "
            "juntaban cerca de las antenas, generaban tanta interferencia que la conexión a "
            "internet del lugar se volvía prácticamente inutilizable. Los científicos terminaron "
            "documentando el fenómeno en un estudio, medio en broma, medio en serio, sobre cómo "
            "la fauna local podía convertirse en un problema de telecomunicaciones. Al final, "
            "resolvieron el problema reprogramando la frecuencia de los sensores."
        ),
        "description": (
            "Una colonia de pingüinos con sensores de investigación llegó a tumbar el wifi de "
            "una base científica. #curiosidades #datoscuriosos #pinguinos #antartida #ciencia"
        ),
        "tags": ["curiosidades", "datos curiosos", "pinguinos", "antartida", "ciencia", "animales"],
    },
]
