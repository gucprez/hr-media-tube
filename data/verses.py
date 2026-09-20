"""Versículos curados para la sección 'Versículo del Día'.

Se elige uno por día del año (determinista, sin depender de servicios
externos) para que la sección cargue rápido y siempre funcione, incluso
sin conexión a internet.
"""

VERSES = [
    {"text": "Lámpara es a mis pies tu palabra, y lumbrera a mi camino.", "ref": "Salmos 119:119"},
    {"text": "Jehová es mi luz y mi salvación; ¿de quién temeré?", "ref": "Salmos 27:1"},
    {"text": "Yo soy la luz del mundo; el que me sigue no andará en tinieblas.", "ref": "Juan 8:12"},
    {"text": "Todo lo puedo en Cristo que me fortalece.", "ref": "Filipenses 4:13"},
    {"text": "Porque de tal manera amó Dios al mundo, que ha dado a su Hijo unigénito.", "ref": "Juan 3:16"},
    {"text": "Encomienda a Jehová tu camino, y confía en él; y él hará.", "ref": "Salmos 37:5"},
    {"text": "El Señor es mi pastor; nada me faltará.", "ref": "Salmos 23:1"},
    {"text": "No temas, porque yo estoy contigo; no desmayes, porque yo soy tu Dios.", "ref": "Isaías 41:10"},
    {"text": "Venid a mí todos los que estáis trabajados y cargados, y yo os haré descansar.", "ref": "Mateo 11:28"},
    {"text": "Todo lo que pidiereis en oración, creyendo, lo recibiréis.", "ref": "Mateo 21:22"},
    {"text": "El gozo de Jehová es vuestra fuerza.", "ref": "Nehemías 8:10"},
    {"text": "Buscad primeramente el reino de Dios y su justicia.", "ref": "Mateo 6:33"},
    {"text": "Porque yo sé los pensamientos que tengo acerca de vosotros, dice Jehová.", "ref": "Jeremías 29:11"},
    {"text": "Sean hechas todas vuestras cosas con amor.", "ref": "1 Corintios 16:14"},
    {"text": "El que habita al abrigo del Altísimo morará bajo la sombra del Omnipotente.", "ref": "Salmos 91:1"},
    {"text": "Fiel es Dios, por el cual fuisteis llamados a la comunión con su Hijo Jesucristo.", "ref": "1 Corintios 1:9"},
    {"text": "Dad gracias en todo, porque esta es la voluntad de Dios para con vosotros.", "ref": "1 Tesalonicenses 5:18"},
    {"text": "Esforzaos y cobrad ánimo; no temáis, ni tengáis miedo de ellos.", "ref": "Deuteronomio 31:6"},
    {"text": "Mas los que esperan a Jehová tendrán nuevas fuerzas.", "ref": "Isaías 40:31"},
    {"text": "La fe es la certeza de lo que se espera, la convicción de lo que no se ve.", "ref": "Hebreos 11:1"},
    {"text": "Alegraos en el Señor siempre. Otra vez digo: ¡Alegraos!", "ref": "Filipenses 4:4"},
    {"text": "Amarás a tu prójimo como a ti mismo.", "ref": "Marcos 12:31"},
    {"text": "Jehová peleará por vosotros, y vosotros estaréis tranquilos.", "ref": "Éxodo 14:14"},
    {"text": "Panorama nuevo cada mañana; grande es tu fidelidad.", "ref": "Lamentaciones 3:23"},
    {"text": "No os afanéis por vuestra vida... mirad las aves del cielo.", "ref": "Mateo 6:25-26"},
    {"text": "El corazón alegre constituye buen remedio.", "ref": "Proverbios 17:22"},
    {"text": "Y conoceréis la verdad, y la verdad os hará libres.", "ref": "Juan 8:32"},
    {"text": "Echando toda vuestra ansiedad sobre él, porque él tiene cuidado de vosotros.", "ref": "1 Pedro 5:7"},
    {"text": "Si Dios es por nosotros, ¿quién contra nosotros?", "ref": "Romanos 8:31"},
    {"text": "Instruye al niño en su camino, y aun cuando fuere viejo no se apartará de él.", "ref": "Proverbios 22:6"},
    {"text": "Clama a mí, y yo te responderé, y te enseñaré cosas grandes y ocultas.", "ref": "Jeremías 33:3"},
]


def verse_of_day(day_of_year: int) -> dict:
    return VERSES[day_of_year % len(VERSES)]
