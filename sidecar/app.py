import os
import tempfile
from functools import lru_cache

from fastapi import FastAPI, HTTPException, UploadFile
from pydantic import BaseModel, Field
from sentence_transformers import CrossEncoder

MAX_PASSAGES = 100
MAX_UPLOAD_BYTES = 25 * 1024 * 1024

app = FastAPI()

reranker = CrossEncoder(os.environ.get("RERANK_MODEL", "BAAI/bge-reranker-base"), max_length=512)


class RerankIn(BaseModel):
    question: str = Field(max_length=2000)
    passages: list[str] = Field(max_length=MAX_PASSAGES)


@lru_cache(maxsize=1)
def pdf_converter():
    from marker.converters.pdf import PdfConverter
    from marker.models import create_model_dict

    return PdfConverter(artifact_dict=create_model_dict())


@app.post("/rerank")
def rerank(body: RerankIn) -> dict:
    scores = reranker.predict([(body.question, p) for p in body.passages])
    return {"scores": [float(s) for s in scores]}


@app.post("/parse")
async def parse(file: UploadFile) -> dict:
    from marker.output import text_from_rendered

    data = await file.read(MAX_UPLOAD_BYTES + 1)
    if len(data) > MAX_UPLOAD_BYTES:
        raise HTTPException(status_code=413, detail="file too large")
    if not data.startswith(b"%PDF"):
        raise HTTPException(status_code=415, detail="only PDF files are accepted")
    with tempfile.NamedTemporaryFile(suffix=".pdf") as handle:
        handle.write(data)
        handle.flush()
        markdown, _, _ = text_from_rendered(pdf_converter()(handle.name))
    return {"markdown": markdown}
