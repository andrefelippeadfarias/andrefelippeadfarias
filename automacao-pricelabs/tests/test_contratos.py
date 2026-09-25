import json
import unittest
from pathlib import Path

from pacing import config

RAIZ = Path(__file__).resolve().parent.parent
try:
    from jsonschema import Draft202012Validator
except ImportError:  # computador do dono: só biblioteca padrão
    Draft202012Validator = None


def schema(nome):
    return json.loads((RAIZ / "schemas" / f"{nome}.schema.json").read_text(encoding="utf-8"))


@unittest.skipUnless(Draft202012Validator, "jsonschema não instalado (só no desenvolvimento)")
class TestContratos(unittest.TestCase):
    def validador(self, nome):
        s = schema(nome)
        Draft202012Validator.check_schema(s)
        return Draft202012Validator(s)

    def test_config_padrao_valida_no_schema(self):
        self.validador("config").validate(json.loads((RAIZ / "config.json").read_text(encoding="utf-8")))

    def test_schema_e_validador_concordam_nos_pisos(self):
        v = self.validador("config")
        base = json.loads((RAIZ / "config.json").read_text(encoding="utf-8"))
        for campo, valor in (("confianca_min", 0.79), ("probabilidade_min", 0.69), ("margem_min", 0.29), ("veto_max", 0.31)):
            c = json.loads(json.dumps(base))
            c["jev"][campo] = valor
            self.assertFalse(v.is_valid(c), campo)
            with self.assertRaises(config.ConfigInvalida):
                config.validar(c)

    def test_exemplos_de_plano_e_diario(self):
        payload = {"date": "2026-09-27", "price": "-10", "price_type": "percent", "reason": "auto-jev 20260926T0530"}
        plano = {"run_id": "20260926T0530", "modo": "ativo", "bloqueios": [],
                 "acoes": [{"tipo": "criar", "listing": "350362___722807", "data": "2026-09-27", "payload": payload,
                            "motivo": "x", "origem": "jev", "confianca": 0.9}]}
        self.validador("plano").validate(plano)
        ruim = json.loads(json.dumps(plano))
        ruim["acoes"][0]["payload"]["price"] = "-30"
        self.assertFalse(self.validador("plano").is_valid(ruim))
        ruim = json.loads(json.dumps(plano))
        ruim["acoes"][0]["payload"]["price_type"] = "fixed"
        self.assertFalse(self.validador("plano").is_valid(ruim))
        ruim = json.loads(json.dumps(plano))
        ruim["acoes"][0]["origem"] = "regra"
        self.assertFalse(self.validador("plano").is_valid(ruim))
        ev = {"ts": "2026-09-26T05:30:00-03:00", "run_id": "20260926T0530", "evento": "intencao",
              "listing": "350362___722807", "data": "2026-09-27", "payload": payload}
        self.validador("diario-escrita").validate(ev)
        self.assertFalse(self.validador("diario-escrita").is_valid({**ev, "evento": "sobrescrita"}))


if __name__ == "__main__":
    unittest.main()
