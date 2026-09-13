"""Agrupa las marcas de palabra de edge-tts en bloques de subtítulos."""
from . import config


def build_caption_chunks(word_boundaries: list[dict], max_chars: int = config.CAPTION_MAX_CHARS_PER_LINE):
    """Agrupa palabras consecutivas en bloques cortos (subtítulos estilo
    'karaoke' de shorts), respetando un largo máximo de caracteres por
    bloque para que el texto no se salga de la pantalla.

    Devuelve una lista de dicts: {start, end, words: [(texto, start, end)]}
    """
    chunks = []
    current_words: list[dict] = []
    current_len = 0

    def flush():
        nonlocal current_words, current_len
        if not current_words:
            return
        chunks.append(
            {
                "start": current_words[0]["start"],
                "end": current_words[-1]["start"] + current_words[-1]["duration"],
                "words": current_words,
            }
        )
        current_words = []
        current_len = 0

    for wb in word_boundaries:
        word_len = len(wb["text"]) + 1
        ends_sentence = wb["text"].rstrip().endswith((".", "!", "?"))
        if current_len + word_len > max_chars and current_words:
            flush()
        current_words.append(wb)
        current_len += word_len
        if ends_sentence and current_len >= max_chars * 0.6:
            flush()

    flush()

    # Extiende cada bloque hasta el inicio del siguiente para que no queden
    # huecos sin subtítulo durante pausas cortas entre frases.
    for i in range(len(chunks) - 1):
        chunks[i]["end"] = chunks[i + 1]["start"]

    return chunks


def caption_for_time(chunks: list[dict], t: float):
    """Devuelve el bloque de subtítulo activo en el instante t (o None)."""
    for chunk in chunks:
        if chunk["start"] <= t <= chunk["end"] + 0.15:
            return chunk
    return None
