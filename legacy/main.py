from fastapi import FastAPI, Form
from fastapi.responses import HTMLResponse

app = FastAPI(title="HR Media Tube")

@app.get("/", response_class=HTMLResponse)
def home():
    return """
    <h1 style="color:white; background:#111827; padding:40px; font-family:sans-serif;">
        ✅ HR Media Tube está funcionando en Railway
    </h1>
    """

@app.post("/analyze")
def analyze(niche: str = Form(...)):
    return {"mensaje": f"Análisis del nicho: {niche}"}